<?php

namespace App\Services\Purchases;

use App\Exceptions\Providers\ProviderNotConfigured;
use App\Exceptions\Purchases\PurchaseException;
use App\Exceptions\Wallet\WalletException;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseStatusChange;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Pricing\PriceResolver;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Services\Providers\ProviderAdapterRegistry;
use App\Services\Providers\ProviderCaller;
use App\Services\Providers\RouteCandidate;
use App\Services\Wallet\WalletService;
use App\Support\Phone\NigerianPhone;
use App\Support\Providers\ProviderOutcome;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only code that creates and executes purchases (Phase 10).
 *
 * Money path: price quote -> one database transaction that inserts the
 * pending purchase (unique customer + idempotency key) and debits the wallet
 * through WalletService (key purchase:{reference}) -> commit -> provider calls
 * outside any database transaction -> each result applied under the purchase
 * row lock:
 * - succeeded: the purchase is successful (cost and margin from the route snapshot);
 * - failed_definite: the next executable route is tried (automatic failover);
 * - unknown (timeout, network, 5xx, undocumented, adapter error): stop; the
 *   purchase stays pending for provider re-checks. Never refunded or failed over.
 * When every route tried failed definitely, the purchase becomes failed with
 * exactly one compensating refund through WalletService (key
 * purchase-refund:{reference}), in the same database transaction.
 *
 * Concurrency: creation locks the wallet row before inserting the purchase
 * (no shared-then-exclusive lock upgrade, so no deadlock between parallel
 * purchases); the purchase row lock serialises attempt creation and result
 * handling; an attempt that is started or unknown blocks further attempts;
 * each route is tried at most once (unique purchase + route); WalletService
 * locks the wallet row. The unique keys and model guards are the last safety net.
 */
class PurchaseService
{
    public function __construct(
        private PriceResolver $prices,
        private ProviderAdapterRegistry $registry,
        private ProviderCaller $caller,
        private WalletService $wallets,
    ) {}

    /** Creates (and debits) a purchase, then executes it. */
    public function purchase(User $user, Plan $plan, string $recipient, ?int $faceValueKobo, string $idempotencyKey): Purchase
    {
        return $this->execute($this->create($user, $plan, $recipient, $faceValueKobo, $idempotencyKey));
    }

    /**
     * Creates the pending purchase and debits the wallet in one database
     * transaction. A repeated key with the same details returns the existing
     * purchase without another debit; with different details it is refused.
     * Nothing is created or debited when the plan is not purchasable, no
     * executable route exists, or the wallet cannot pay.
     *
     * @throws PurchaseException
     */
    public function create(User $user, Plan $plan, string $recipient, ?int $faceValueKobo, string $idempotencyKey): Purchase
    {
        $canonical = NigerianPhone::normalize($recipient) ?? throw new PurchaseException('Enter a valid Nigerian phone number.');
        $fingerprint = Purchase::fingerprint($plan->id, $canonical, $faceValueKobo);

        if ($existing = $this->existing($user, $idempotencyKey, $fingerprint)) {
            return $existing;
        }

        $quote = $this->prices->quoteFor($plan, $user, $faceValueKobo);
        if (! $quote->available) {
            throw new PurchaseException($quote->reason ?? 'This plan is not available.');
        }
        if ($this->registry->executableFor($plan) === []) {
            throw new PurchaseException('This plan is not available right now.');
        }

        $plan->loadMissing('product.service');
        $wallet = $this->wallets->walletFor($user);

        try {
            return DB::transaction(function () use ($user, $plan, $wallet, $quote, $canonical, $idempotencyKey, $fingerprint) {
                // Lock the wallet first: inserting the purchase takes a shared lock on the wallet row
                // (foreign key) and the debit needs an exclusive one; locking it up front makes
                // parallel purchases on one wallet queue instead of deadlocking.
                Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                $purchase = (new Purchase)->forceFill([
                    'reference' => WalletService::reference('PUR'),
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                    'plan_id' => $plan->id,
                    'service_id' => $plan->product->service_id,
                    'service_name' => $plan->product->service->name,
                    'product_name' => $plan->product->name,
                    'plan_name' => $plan->name,
                    'network' => $plan->product->network,
                    'user_type' => $user->user_type,
                    'amount_type' => $plan->amount_type,
                    'recipient' => $canonical,
                    'face_value_kobo' => $quote->faceValueKobo,
                    'discount_kobo' => $quote->discountKobo,
                    'fee_kobo' => $quote->feeKobo,
                    'amount_kobo' => $quote->amountKobo,
                    'status' => PurchaseStatus::Pending,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ]);
                $purchase->save(); // the unique (customer, key) index stops a parallel duplicate here, before any debit

                $debit = $this->wallets->debit($wallet, $purchase->amount_kobo, LedgerEntryType::PurchaseDebit, TransactionType::Purchase,
                    "{$purchase->service_name}: {$purchase->plan_name}", 'purchase:'.$purchase->reference, null, ['purchase' => $purchase->reference]);
                $purchase->forceFill(['debit_transaction_id' => $debit->transaction->id])->save();
                $this->record($purchase, null, PurchaseStatus::Pending, PurchaseSource::Customer);

                return $purchase;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // A parallel request with the same key won the race (its debit is the only one).
            return $this->existing($user, $idempotencyKey, $fingerprint) ?? throw $e;
        } catch (WalletException $e) {
            throw new PurchaseException($e->getMessage());
        }
    }

    /**
     * Tries the executable routes in order. Safe to call repeatedly and in
     * parallel: only a pending purchase whose attempts so far all failed
     * definitely gets a new attempt, one at a time. Returns the current purchase.
     */
    public function execute(Purchase $purchase): Purchase
    {
        while (true) {
            $next = $this->startNextAttempt($purchase);
            if ($next === null) {
                return $purchase->fresh();
            }
            [$attempt, $candidate] = $next;
            if ($attempt === null) {
                return $this->refundAllFailed($purchase); // nothing left to try and nothing was delivered
            }

            $result = $this->call($purchase->fresh(), $attempt, $candidate);
            $after = $this->applyResult($purchase, $attempt, $result);
            if ($after->status !== PurchaseStatus::Pending || $result->outcome !== ProviderOutcome::FailedDefinite) {
                return $after; // succeeded, or unknown: stop (no failover, no refund)
            }
        }
    }

    /**
     * Under the purchase row lock, creates the next attempt.
     * Returns null when nothing may be tried now (not pending, or an attempt is
     * started/unknown), [null, null] when every route was tried and failed
     * definitely, otherwise [attempt, candidate].
     *
     * @return array{0: ?PurchaseAttempt, 1: ?RouteCandidate}|null
     */
    private function startNextAttempt(Purchase $purchase): ?array
    {
        return DB::transaction(function () use ($purchase) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PurchaseStatus::Pending) {
                return null;
            }
            $attempts = PurchaseAttempt::where('purchase_id', $locked->id)->get();
            if ($attempts->contains(fn (PurchaseAttempt $a) => ! $a->status->isDefinite())) {
                return null; // in flight, or unclear: only a provider re-check may continue
            }
            if ($attempts->contains(fn (PurchaseAttempt $a) => $a->status === PurchaseAttemptStatus::Succeeded)) {
                return null;
            }

            $tried = $attempts->pluck('plan_provider_route_id')->all();
            foreach ($this->registry->executableFor($locked->plan) as $candidate) {
                if (in_array($candidate->route->id, $tried, true)) {
                    continue;
                }
                try {
                    $this->registry->contextFor($candidate->route->provider); // refuse a route we could not call, before recording anything
                } catch (ProviderNotConfigured) {
                    continue;
                }
                $route = $candidate->route;
                $attempt = (new PurchaseAttempt)->forceFill([
                    'purchase_id' => $locked->id,
                    'attempt_number' => $attempts->count() + 1,
                    'plan_provider_route_id' => $route->id,
                    'provider_id' => $route->provider_id,
                    'route_priority' => $route->priority,
                    'provider_plan_code' => $route->provider_plan_code,
                    'cost_type' => $route->cost_type,
                    'cost_kobo' => $route->cost_kobo,
                    'cost_discount_bps' => $route->cost_discount_bps,
                    'request_reference' => WalletService::reference('PRA'),
                    'status' => PurchaseAttemptStatus::Started,
                    'started_at' => now(),
                ]);
                $attempt->save();

                return [$attempt, $candidate];
            }

            return [null, null];
        }, 3);
    }

    /** The provider call, outside any database transaction. */
    private function call(Purchase $purchase, PurchaseAttempt $attempt, RouteCandidate $candidate): ProviderResult
    {
        $provider = $candidate->route->provider;
        $adapter = $this->registry->adapterFor($provider);
        try {
            $context = $this->registry->contextFor($provider);
        } catch (ProviderNotConfigured) {
            return ProviderResult::unknown('not_configured', 'The provider is not configured.');
        }

        return $this->caller->purchase($adapter, new ProviderPurchaseRequest(
            $attempt->request_reference,
            $purchase->plan->product->service->slug,
            $purchase->network?->value,
            $attempt->provider_plan_code,
            $purchase->recipient,
            $purchase->amount_kobo,
            $purchase->face_value_kobo,
        ), $context);
    }

    /** Records the provider result on the attempt and, for success, settles the purchase. */
    private function applyResult(Purchase $purchase, PurchaseAttempt $attempt, ProviderResult $result): Purchase
    {
        return DB::transaction(function () use ($purchase, $attempt, $result) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $current = PurchaseAttempt::whereKey($attempt->id)->firstOrFail();
            if ($current->status !== PurchaseAttemptStatus::Started) {
                return $locked; // already settled elsewhere
            }

            $status = match ($result->outcome) {
                ProviderOutcome::Succeeded => PurchaseAttemptStatus::Succeeded,
                ProviderOutcome::FailedDefinite => PurchaseAttemptStatus::FailedDefinite,
                ProviderOutcome::Unknown => PurchaseAttemptStatus::Unknown,
            };
            $current->forceFill([
                'status' => $status,
                'provider_reference' => $result->providerReference,
                'error_code' => $result->errorCode,
                'error_message' => $result->outcome === ProviderOutcome::Succeeded ? null : $result->message,
                'finished_at' => now(),
                'duration_ms' => (int) min(4_000_000_000, $current->started_at->diffInMilliseconds(now(), true)),
            ])->save();

            if ($status === PurchaseAttemptStatus::Succeeded && $locked->status === PurchaseStatus::Pending) {
                $cost = $current->costKobo($locked->face_value_kobo);

                return $this->transition($locked, PurchaseStatus::Successful, PurchaseSource::Execution, [
                    'successful_attempt_id' => $current->id,
                    'cost_kobo' => $cost,
                    'margin_kobo' => $cost === null ? null : $locked->amount_kobo - $cost,
                    'failure_reason' => null,
                    'next_check_at' => null,
                    'completed_at' => now(),
                ]);
            }
            if ($status === PurchaseAttemptStatus::Unknown) {
                $locked->forceFill(['next_check_at' => now()->addMinutes(config('purchases.recheck_schedule_minutes')[0])])->save();
            }

            return $locked;
        }, 3);
    }

    /**
     * Fails the purchase with one compensating refund, only when it is still
     * pending, debited, and every attempt (if any) failed definitely.
     */
    private function refundAllFailed(Purchase $purchase): Purchase
    {
        try {
            return DB::transaction(function () use ($purchase) {
                $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== PurchaseStatus::Pending || $locked->debit_transaction_id === null) {
                    return $locked;
                }
                $attempts = PurchaseAttempt::where('purchase_id', $locked->id)->get();
                if ($attempts->contains(fn (PurchaseAttempt $a) => $a->status !== PurchaseAttemptStatus::FailedDefinite)) {
                    return $locked; // something may have been delivered: never refund
                }

                $refund = $this->wallets->credit(Wallet::findOrFail($locked->wallet_id), $locked->amount_kobo, LedgerEntryType::PurchaseRefund,
                    TransactionType::Purchase, "Refund: {$locked->service_name}: {$locked->plan_name}", 'purchase-refund:'.$locked->reference, null,
                    ['purchase' => $locked->reference]);

                return $this->transition($locked, PurchaseStatus::Failed, PurchaseSource::Execution, [
                    'refund_transaction_id' => $refund->transaction->id,
                    'failure_reason' => $attempts->isEmpty() ? 'No provider was available.' : 'Every provider declined the purchase.',
                    'next_check_at' => null,
                    'completed_at' => now(),
                ]);
            }, 3);
        } catch (WalletException $e) {
            // The refund could not be posted (should not happen); keep it pending and visible, never lose it.
            Log::error('Purchase refund could not be posted', ['purchase' => $purchase->reference, 'reason' => $e->getMessage()]);

            return $purchase->fresh();
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function transition(Purchase $locked, PurchaseStatus $to, PurchaseSource $source, array $attributes, ?SystemUser $actor = null): Purchase
    {
        $from = $locked->status;
        $locked->forceFill(['status' => $to] + $attributes)->save();
        $this->record($locked, $from, $to, $source, $actor, $attributes['failure_reason'] ?? null);

        return $locked;
    }

    private function record(Purchase $purchase, ?PurchaseStatus $from, PurchaseStatus $to, PurchaseSource $source, ?SystemUser $actor = null, ?string $note = null): void
    {
        (new PurchaseStatusChange)->forceFill([
            'purchase_id' => $purchase->id,
            'old_status' => $from,
            'new_status' => $to,
            'source' => $source,
            'changed_by' => $actor?->id,
            'note' => $note !== null ? Str::limit($note, 250) : null,
        ])->save();
    }

    private function existing(User $user, string $key, string $fingerprint): ?Purchase
    {
        $purchase = Purchase::where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if ($purchase !== null && ! hash_equals($purchase->request_fingerprint, $fingerprint)) {
            throw new PurchaseException('This request was already used for a different purchase. Please start again.');
        }

        return $purchase;
    }
}
