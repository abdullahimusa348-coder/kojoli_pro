<?php

namespace App\Services\Purchases;

use App\Exceptions\Providers\ProviderNotConfigured;
use App\Exceptions\Purchases\PurchaseException;
use App\Exceptions\Wallet\WalletException;
use App\Models\Plan;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\PurchaseIdentityRecipient;
use App\Models\PurchaseResult;
use App\Models\PurchaseStatusChange;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Pricing\PriceResolver;
use App\Services\Providers\Data\ProviderPurchaseRequest;
use App\Services\Providers\Data\ProviderQueryRequest;
use App\Services\Providers\Data\ProviderResult;
use App\Services\Providers\Data\ProviderResultFields;
use App\Services\Providers\ProviderAdapterRegistry;
use App\Services\Providers\ProviderCaller;
use App\Services\Providers\RouteCandidate;
use App\Services\Wallet\WalletService;
use App\Support\Catalog\AmountType;
use App\Support\MaintenanceMode;
use App\Support\Phone\NigerianPhone;
use App\Support\Providers\ProviderOutcome;
use App\Support\Purchases\IdentityHasher;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

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
 * Recipients (Phase 11): the plan's service decides the recipient type. Phone
 * purchases (Data, Airtime and every service other than NIN/BVN/Exam PIN)
 * work exactly as in Phase 10. A NIN/BVN purchase needs the customer's
 * consent and a fixed-price plan; its number is stored only in
 * PurchaseIdentityRecipient (encrypted, masked, keyed hashes), written in the
 * creation transaction, and decrypted only to build the provider request
 * (purchases:verify only checks that it can be read); when it cannot be read,
 * nothing is sent. An Exam PIN purchase (Phase 11 CP4, RecipientType::None)
 * has no recipient of any kind: any recipient or face value is refused, its
 * plan must be fixed-price, and the provider is sent an empty recipient with
 * recipientType "none". Smile Data is refused until its inputs are defined.
 *
 * Results (Phase 11 CP2, CP4): a NIN/BVN or Exam PIN purchase succeeds only
 * with the result fields the provider delivered (for Exam PIN, at least one
 * of them not blank). A success without them is unclear (result_missing:
 * re-checked, never refunded or failed over). The result is stored once,
 * encrypted (a NIN/BVN result with the purchased number replaced by its
 * mask), in the transaction that marks the purchase successful. An Exam PIN
 * success whose provider reference repeats a delivered value (such as the
 * PIN) keeps no provider reference, as staff see references. Phone
 * purchases ignore any result fields.
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
    public function purchase(User $user, Plan $plan, #[\SensitiveParameter] string $recipient, ?int $faceValueKobo, string $idempotencyKey,
        ?int $confirmedAmountKobo = null, bool $consented = false): Purchase
    {
        return $this->execute($this->create($user, $plan, $recipient, $faceValueKobo, $idempotencyKey, $confirmedAmountKobo, $consented));
    }

    /**
     * Creates the pending purchase and debits the wallet in one database
     * transaction. A repeated key with the same details returns the existing
     * purchase without another debit (also during maintenance mode); with
     * different details it is refused. Nothing is created or debited when
     * maintenance mode is on, the plan is not purchasable, no executable
     * route exists, or the wallet cannot pay. The price is always
     * resolved here on the server; $confirmedAmountKobo (what the customer saw
     * and confirmed) is only compared with it, and a difference is refused.
     * $recipient is a phone number, or the NIN/BVN for those services, which
     * also need $consented and a fixed-price plan; for Exam PIN it must be
     * empty (no recipient), with no face value and a fixed-price plan.
     *
     * @throws PurchaseException
     */
    public function create(User $user, Plan $plan, #[\SensitiveParameter] string $recipient, ?int $faceValueKobo, string $idempotencyKey,
        ?int $confirmedAmountKobo = null, bool $consented = false): Purchase
    {
        $plan->loadMissing('product.service');
        $type = RecipientType::forServiceSlug($plan->product->service->slug) ?? throw new PurchaseException('This service is not available yet.');
        if ($type === RecipientType::Phone) {
            $canonical = NigerianPhone::normalize($recipient) ?? throw new PurchaseException('Enter a valid Nigerian phone number.');
            $fingerprints = [Purchase::fingerprint($plan->id, $canonical, $faceValueKobo)];
        } elseif ($type->isIdentity()) {
            $canonical = $type->normalize($recipient) ?? throw new PurchaseException($type->invalidMessage());
            $fingerprints = IdentityHasher::keyedFingerprints($plan->id, $type, $canonical, $faceValueKobo); // current app key first
        } else {
            // Exam PIN: no recipient of any kind (no phone, NIN, BVN, email or candidate details) and no amount to choose.
            if ($recipient !== '') {
                throw new PurchaseException('This service takes no recipient.');
            }
            if ($faceValueKobo !== null) {
                throw new PurchaseException('This plan is sold at a fixed price only.');
            }
            $canonical = '';
            $fingerprints = [Purchase::recipientlessFingerprint($plan->id)];
        }

        if ($existing = $this->existing($user, $idempotencyKey, $type, $fingerprints, $confirmedAmountKobo)) {
            return $existing;
        }
        // After the lookup above (a repeated submission still gets its purchase), before any pricing, debit or provider call.
        if (MaintenanceMode::active()) {
            throw new PurchaseException(MaintenanceMode::MESSAGE);
        }
        if ($type->isIdentity()) {
            if (! $consented) {
                throw new PurchaseException("Your consent is required for a {$type->label()} purchase.");
            }
            if ($plan->amount_type !== AmountType::Fixed) {
                throw new PurchaseException("{$type->label()} plans are sold at a fixed price only.");
            }
        } elseif ($type === RecipientType::None && $plan->amount_type !== AmountType::Fixed) {
            throw new PurchaseException('This plan is sold at a fixed price only.');
        }

        $quote = $this->prices->quoteFor($plan, $user, $faceValueKobo);
        if (! $quote->available) {
            throw new PurchaseException($quote->reason ?? 'This plan is not available.');
        }
        if ($confirmedAmountKobo !== null && $confirmedAmountKobo !== $quote->amountKobo) {
            throw new PurchaseException('The price has changed. Please review the new price and confirm again.');
        }
        if ($this->registry->executableFor($plan) === []) {
            throw new PurchaseException('This plan is not available right now.');
        }

        $wallet = $this->wallets->walletFor($user);

        try {
            return DB::transaction(function () use ($user, $plan, $wallet, $quote, $type, $canonical, $idempotencyKey, $fingerprints) {
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
                    'recipient_type' => $type,
                    'recipient' => $type === RecipientType::Phone ? $canonical : null,
                    'face_value_kobo' => $quote->faceValueKobo,
                    'discount_kobo' => $quote->discountKobo,
                    'fee_kobo' => $quote->feeKobo,
                    'amount_kobo' => $quote->amountKobo,
                    'status' => PurchaseStatus::Pending,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $type === RecipientType::Phone ? $fingerprints[0] : null,
                ]);
                $purchase->save(); // the unique (customer, key) index stops a parallel duplicate here, before any debit
                if ($type->isIdentity()) {
                    (new PurchaseIdentityRecipient)->forceFill([
                        'purchase_id' => $purchase->id,
                        'encrypted_value' => $canonical,
                        'masked_value' => $type->mask($canonical),
                        'lookup_hash' => IdentityHasher::lookupHash($type, $canonical),
                        'keyed_fingerprint' => $fingerprints[0],
                        'consented_at' => now(),
                    ])->save();
                }

                $debit = $this->wallets->debit($wallet, $purchase->amount_kobo, LedgerEntryType::PurchaseDebit, TransactionType::Purchase,
                    "{$purchase->service_name}: {$purchase->plan_name}", 'purchase:'.$purchase->reference, null, ['purchase' => $purchase->reference]);
                $purchase->forceFill(['debit_transaction_id' => $debit->transaction->id])->save();
                $this->record($purchase, null, PurchaseStatus::Pending, PurchaseSource::Customer);

                return $purchase;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // A parallel request with the same key won the race (its debit is the only one).
            return $this->existing($user, $idempotencyKey, $type, $fingerprints, $confirmedAmountKobo) ?? throw $e;
        } catch (WalletException $e) {
            throw new PurchaseException($e->getMessage());
        }
    }

    /**
     * Tries the executable routes in order. Safe to call repeatedly and in
     * parallel: only a pending purchase whose attempts so far all failed
     * definitely gets a new attempt, one at a time. Returns the current purchase.
     * The recipient is worked out first: when a NIN/BVN cannot be read, no
     * attempt is made and nothing is sent (fail closed; it stays pending). An
     * Exam PIN purchase has none: the provider is sent an empty recipient.
     */
    public function execute(Purchase $purchase): Purchase
    {
        $recipient = $this->providerRecipient($purchase);
        if ($recipient === null) {
            return $purchase->fresh();
        }

        while (true) {
            $next = $this->startNextAttempt($purchase);
            if ($next === null) {
                return $purchase->fresh();
            }
            [$attempt, $candidate] = $next;
            if ($attempt === null) {
                return $this->refundAllFailed($purchase); // nothing left to try and nothing was delivered
            }

            $result = $this->call($purchase->fresh(), $attempt, $candidate, $recipient);
            $after = $this->applyResult($purchase, $attempt, $result, $recipient);
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

    /**
     * What the provider is asked to deliver to: the phone number (Phase 10,
     * no query), the NIN/BVN decrypted in memory, or for Exam PIN nothing (an
     * empty recipient). Null when a phone or NIN/BVN cannot be read, logged by
     * purchase reference only.
     */
    private function providerRecipient(Purchase $purchase): ?string
    {
        $recipient = match ($purchase->recipient_type) {
            RecipientType::Phone => $purchase->recipient,
            RecipientType::Nin, RecipientType::Bvn => PurchaseIdentityRecipient::where('purchase_id', $purchase->id)->first()?->number($purchase->recipient_type),
            RecipientType::None => '',
        };
        if ($recipient === null) {
            Log::error('Purchase recipient unavailable', ['purchase' => $purchase->reference]);
        }

        return $recipient;
    }

    /** The provider call, outside any database transaction. */
    private function call(Purchase $purchase, PurchaseAttempt $attempt, RouteCandidate $candidate, #[\SensitiveParameter] string $recipient): ProviderResult
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
            $recipient,
            $purchase->amount_kobo,
            $purchase->face_value_kobo,
            $purchase->recipient_type->value,
        ), $context);
    }

    /** Records the provider result on the attempt and, for success, settles the purchase. */
    private function applyResult(Purchase $purchase, PurchaseAttempt $attempt, ProviderResult $result, #[\SensitiveParameter] string $recipient): Purchase
    {
        return DB::transaction(function () use ($purchase, $attempt, $result, $recipient) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $current = PurchaseAttempt::whereKey($attempt->id)->firstOrFail();
            if ($current->status !== PurchaseAttemptStatus::Started) {
                return $locked; // already settled elsewhere
            }
            [$result, $number] = $this->deliverable($locked, $result, $recipient);

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
                return $this->succeedLocked($locked, $current, PurchaseSource::Execution, null, $result->fields, $number);
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

                return $this->refundLocked($locked, PurchaseSource::Execution,
                    $attempts->isEmpty() ? 'No provider was available.' : 'Every provider declined the purchase.');
            }, 3);
        } catch (WalletException $e) {
            // The refund could not be posted (should not happen); keep it pending and visible, never lose it.
            Log::error('Purchase refund could not be posted', ['purchase' => $purchase->reference, 'reason' => $e->getMessage()]);

            return $purchase->fresh();
        }
    }

    /**
     * Re-checks one pending or review purchase (scheduler or staff; same logic).
     * - An attempt with an unknown outcome is queried with the provider's
     *   documented status lookup, outside any database lock, and the result is
     *   applied under the purchase row lock: succeeded -> successful;
     *   failed_definite -> failed with exactly one refund, never a failover
     *   after an unclear attempt; unknown or no lookup -> stays as it is and
     *   the next check is scheduled; review after review_after_hours.
     * - An attempt left "started" past stale_attempt_minutes (interrupted call)
     *   is treated as unknown first.
     * - A pending purchase with nothing unclear (no attempt yet, or every
     *   attempt failed definitely) simply continues normal execution.
     * Review never refunds or fails over on its own.
     */
    public function recheck(Purchase $purchase, PurchaseSource $source, ?SystemUser $actor = null): Purchase
    {
        $purchase = $purchase->fresh();
        if (! in_array($purchase->status, [PurchaseStatus::Pending, PurchaseStatus::Review], true)) {
            return $purchase;
        }
        $this->expireStaleAttempt($purchase);

        $attempts = PurchaseAttempt::where('purchase_id', $purchase->id)->get();
        if ($attempts->contains(fn (PurchaseAttempt $a) => $a->status === PurchaseAttemptStatus::Started)) {
            return $purchase; // a provider call is still in flight
        }
        $unknown = $attempts->first(fn (PurchaseAttempt $a) => $a->status === PurchaseAttemptStatus::Unknown);
        if ($unknown === null) {
            return $purchase->status === PurchaseStatus::Pending ? $this->execute($purchase) : $purchase;
        }

        $result = null;
        $adapter = $this->registry->adapterFor($unknown->provider);
        if ($adapter !== null && $adapter->canQuery()) {
            try {
                $result = $this->caller->query($adapter, new ProviderQueryRequest(
                    $unknown->request_reference, $unknown->provider_reference, $purchase->plan->product->service->slug,
                ), $this->registry->contextFor($unknown->provider));
            } catch (ProviderNotConfigured) {
                $result = null;
            }
        }

        return $this->applyRecheck($purchase, $unknown, $result, $source, $actor);
    }

    /**
     * Re-checks the purchases that are due, oldest first, in one small batch.
     *
     * @return array{checked: int, settled: int, review: int, errors: int}
     */
    public function reconcile(): array
    {
        $stats = ['checked' => 0, 'settled' => 0, 'review' => 0, 'errors' => 0];
        $minAge = now()->subMinutes(config('purchases.reconcile_min_age_minutes'));
        $due = Purchase::whereIn('status', [PurchaseStatus::Pending->value, PurchaseStatus::Review->value])
            ->where(fn ($q) => $q->where('next_check_at', '<=', now())
                ->orWhere(fn ($q) => $q->whereNull('next_check_at')->where('created_at', '<=', $minAge)))
            ->orderByRaw('COALESCE(next_check_at, created_at)')->orderBy('id')
            ->limit(config('purchases.reconcile_batch'))->get();

        foreach ($due as $purchase) {
            $stats['checked']++;
            $before = $purchase->status;
            try {
                $after = $this->recheck($purchase, PurchaseSource::Reconcile);
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('Purchase re-check failed', ['purchase' => $purchase->reference, 'exception' => $e::class]);

                continue;
            }
            if ($after->isFinal()) {
                $stats['settled']++;
            } elseif ($after->status === PurchaseStatus::Review && $before !== PurchaseStatus::Review) {
                $stats['review']++;
            }
        }

        return $stats;
    }

    /** Applies a re-check result under the purchase row lock. A null result means no documented lookup was possible. */
    private function applyRecheck(Purchase $purchase, PurchaseAttempt $unknown, ?ProviderResult $result, PurchaseSource $source, ?SystemUser $actor): Purchase
    {
        try {
            return DB::transaction(function () use ($purchase, $unknown, $result, $source, $actor) {
                $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
                $attempt = PurchaseAttempt::whereKey($unknown->id)->firstOrFail();
                if (! in_array($locked->status, [PurchaseStatus::Pending, PurchaseStatus::Review], true) || $attempt->status !== PurchaseAttemptStatus::Unknown) {
                    return $locked; // settled by a parallel re-check
                }

                $attempt->forceFill(['last_checked_at' => now()]);
                $number = null;
                if ($result !== null) {
                    [$result, $number] = $this->deliverable($locked, $result);
                }
                if ($attempt->provider_reference === null && $result?->providerReference !== null) {
                    $attempt->provider_reference = $result->providerReference;
                }

                if ($result?->outcome === ProviderOutcome::Succeeded) {
                    $attempt->forceFill(['status' => PurchaseAttemptStatus::Succeeded, 'error_code' => null, 'error_message' => null])->save();

                    return $this->succeedLocked($locked, $attempt, $source, $actor, $result->fields, $number);
                }
                if ($result?->outcome === ProviderOutcome::FailedDefinite) {
                    $attempt->forceFill(['status' => PurchaseAttemptStatus::FailedDefinite, 'error_code' => $result->errorCode, 'error_message' => $result->message])->save();
                    $others = PurchaseAttempt::where('purchase_id', $locked->id)->where('id', '!=', $attempt->id)->get();
                    if ($others->contains(fn (PurchaseAttempt $a) => $a->status !== PurchaseAttemptStatus::FailedDefinite)) {
                        return $locked; // never refund while anything else may have been delivered
                    }

                    // No failover after an attempt that was unclear: refund instead.
                    return $this->refundLocked($locked, $source, 'The provider confirmed the purchase failed.', $actor);
                }

                if ($result !== null) {
                    $attempt->forceFill(['error_code' => $result->errorCode, 'error_message' => $result->message]);
                }
                $attempt->save();

                $locked->forceFill(['check_count' => $locked->check_count + 1]);
                $locked->next_check_at = $this->nextCheckAt($locked, $attempt);
                if ($locked->status === PurchaseStatus::Pending && $locked->created_at->copy()->addHours(config('purchases.review_after_hours'))->isPast()) {
                    return $this->transition($locked, PurchaseStatus::Review, $source,
                        ['failure_reason' => 'No definite provider outcome after '.config('purchases.review_after_hours').' hours.'], $actor);
                }
                $locked->save();

                return $locked;
            }, 3);
        } catch (WalletException $e) {
            Log::error('Purchase refund could not be posted', ['purchase' => $purchase->reference, 'reason' => $e->getMessage()]);

            return $purchase->fresh();
        }
    }

    /** Next check: the approved offsets after the unclear attempt, then a fixed interval. */
    private function nextCheckAt(Purchase $locked, PurchaseAttempt $attempt): Carbon
    {
        $schedule = config('purchases.recheck_schedule_minutes');
        $base = $attempt->finished_at ?? $attempt->started_at;
        if ($locked->check_count < count($schedule)) {
            $at = $base->copy()->addMinutes($schedule[$locked->check_count]);

            return $at->isPast() ? now() : $at;
        }

        return now()->addMinutes(config('purchases.recheck_every_minutes'));
    }

    /** Turns an attempt left "started" (interrupted call) into unknown, so it can be re-checked. */
    private function expireStaleAttempt(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $stale = PurchaseAttempt::where('purchase_id', $purchase->id)->where('status', PurchaseAttemptStatus::Started->value)
                ->where('started_at', '<=', now()->subMinutes(config('purchases.stale_attempt_minutes')))->first();
            $stale?->forceFill(['status' => PurchaseAttemptStatus::Unknown, 'error_code' => 'interrupted',
                'error_message' => 'The provider call did not complete.', 'finished_at' => now()])->save();
        }, 3);
    }

    /**
     * Purchases that require a result (NIN, BVN, Exam PIN): a succeeded answer
     * counts only with its result fields, for Exam PIN only when at least one
     * delivered value is not blank, and for NIN/BVN only when the purchased
     * number can be read to take it out of them; otherwise the answer is
     * unclear (result_missing: re-checked, never refunded or failed over).
     * An Exam PIN success whose provider reference repeats a delivered value
     * (such as the PIN) keeps no provider reference: attempts and their
     * references are shown to staff.
     * Returns the answer to apply and, for a NIN/BVN success, the purchased
     * number. Phone purchases: the answer as it is.
     *
     * @return array{0: ProviderResult, 1: ?string}
     */
    private function deliverable(Purchase $locked, ProviderResult $result, #[\SensitiveParameter] ?string $number = null): array
    {
        if ($result->outcome !== ProviderOutcome::Succeeded || ! $locked->recipient_type->requiresResult()) {
            return [$result, null];
        }
        if ($result->fields === null || (! $locked->recipient_type->isIdentity() && ! PurchaseResult::hasValue($result->fields->all()))) {
            return [ProviderResult::unknown('result_missing', 'The provider reported success without the result.', $result->providerReference), null];
        }
        if (! $locked->recipient_type->isIdentity()) {
            return [$this->withoutRepeatedReference($locked, $result), null]; // Exam PIN: no number to take out of the result
        }
        $number ??= $this->providerRecipient($locked);
        if ($number === null) {
            return [ProviderResult::unknown('recipient_unavailable', 'The result was not stored: the purchased number could not be read.', $result->providerReference), null];
        }

        return [$result, $number];
    }

    /**
     * The Exam PIN success as delivered, or without its provider reference
     * when that reference contains a delivered value as it is written (such
     * as the PIN or serial). Logged by purchase reference only.
     */
    private function withoutRepeatedReference(Purchase $locked, ProviderResult $result): ProviderResult
    {
        $reference = $result->providerReference;
        $repeats = $reference !== null && collect($result->fields?->all() ?? [])
            ->map(fn (array $field) => preg_replace('/\A[\p{Z}\p{Cf}]+|[\p{Z}\p{Cf}]+\z/u', '', $field['value']))
            ->contains(fn (string $value) => $value !== '' && str_contains($reference, $value));
        if (! $repeats) {
            return $result;
        }
        Log::warning('Provider reference not stored: it repeats a delivered result value', ['purchase' => $locked->reference]);

        return ProviderResult::succeeded(null, $result->message, $result->fields);
    }

    /**
     * Stores the result of a purchase that requires one, for its delivering
     * attempt, encrypted, in the caller's transaction: a NIN/BVN result with
     * the purchased number replaced by its mask wherever it appears (also
     * written with spaces, dots or dashes); an Exam PIN result as delivered
     * (there is no number in it to take out).
     */
    private function storeResult(Purchase $locked, PurchaseAttempt $attempt, ?ProviderResultFields $fields, #[\SensitiveParameter] ?string $number): void
    {
        if (! $locked->recipient_type->isIdentity()) {
            $stored = $fields?->all() ?? throw new LogicException('An Exam PIN success needs its result.');
        } else {
            if ($fields === null || $number === null) {
                throw new LogicException('A NIN or BVN success needs its result and the purchased number.');
            }
            $pattern = '/'.implode('[\s\p{Zs}.\-]?', str_split($number)).'/u';
            $mask = $locked->recipient_type->mask($number);
            $stored = array_map(fn (array $field) => ['key' => $field['key'], 'label' => preg_replace($pattern, $mask, $field['label']),
                'value' => preg_replace($pattern, $mask, $field['value'])], $fields->all());
        }

        (new PurchaseResult)->forceFill([
            'purchase_id' => $locked->id,
            'purchase_attempt_id' => $attempt->id,
            'encrypted_fields' => $stored,
            'field_count' => count($stored),
        ])->save();
    }

    /**
     * Marks a locked pending/review purchase successful through its delivering
     * attempt, with cost and margin from the route snapshot; the result of a
     * purchase that requires one (NIN, BVN, Exam PIN) is stored first, in the
     * same transaction.
     */
    private function succeedLocked(Purchase $locked, PurchaseAttempt $attempt, PurchaseSource $source, ?SystemUser $actor = null,
        ?ProviderResultFields $fields = null, #[\SensitiveParameter] ?string $number = null): Purchase
    {
        if ($locked->recipient_type->requiresResult()) {
            $this->storeResult($locked, $attempt, $fields, $number);
        }
        $cost = $attempt->costKobo($locked->face_value_kobo);

        return $this->transition($locked, PurchaseStatus::Successful, $source, [
            'successful_attempt_id' => $attempt->id,
            'cost_kobo' => $cost,
            'margin_kobo' => $cost === null ? null : $locked->amount_kobo - $cost,
            'failure_reason' => null,
            'next_check_at' => null,
            'completed_at' => now(),
        ], $actor);
    }

    /**
     * Fails a locked purchase whose attempts all failed definitely, with the
     * single compensating refund through WalletService, in the caller's
     * transaction. A repeated call is a no-op (status check, wallet key and
     * unique refund link).
     */
    private function refundLocked(Purchase $locked, PurchaseSource $source, string $reason, ?SystemUser $actor = null): Purchase
    {
        $refund = $this->wallets->credit(Wallet::findOrFail($locked->wallet_id), $locked->amount_kobo, LedgerEntryType::PurchaseRefund,
            TransactionType::Purchase, "Refund: {$locked->service_name}: {$locked->plan_name}", 'purchase-refund:'.$locked->reference, null,
            ['purchase' => $locked->reference]);

        return $this->transition($locked, PurchaseStatus::Failed, $source, [
            'refund_transaction_id' => $refund->transaction->id,
            'failure_reason' => $reason,
            'next_check_at' => null,
            'completed_at' => now(),
        ], $actor);
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

    /**
     * The customer's purchase for this key, if any; it must be the purchase
     * this request asks for. Never compared across recipient types.
     * - phone: the stored Phase 10 fingerprint must match (unchanged);
     * - NIN/BVN: the identity recipient's keyed fingerprint must match one of
     *   $fingerprints (the request under the current and each previous app key);
     * - Exam PIN: the recipient-less fingerprint of the purchase's own plan
     *   (nothing is stored) must match, and so must the confirmed amount
     *   when the request carries one.
     *
     * @param  non-empty-list<string>  $fingerprints
     */
    private function existing(User $user, string $key, RecipientType $type, array $fingerprints, ?int $confirmedAmountKobo = null): ?Purchase
    {
        $purchase = Purchase::where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if ($purchase !== null && ! $this->sameRequest($purchase, $type, $fingerprints, $confirmedAmountKobo)) {
            throw new PurchaseException('This request was already used for a different purchase. Please start again.');
        }

        return $purchase;
    }

    /** @param  non-empty-list<string>  $fingerprints */
    private function sameRequest(Purchase $purchase, RecipientType $type, array $fingerprints, ?int $confirmedAmountKobo): bool
    {
        if ($purchase->recipient_type !== $type) {
            return false;
        }
        if ($type === RecipientType::Phone) {
            return hash_equals($purchase->request_fingerprint, $fingerprints[0]);
        }
        if ($type === RecipientType::None) {
            return hash_equals(Purchase::recipientlessFingerprint($purchase->plan_id), $fingerprints[0])
                && ($confirmedAmountKobo === null || $confirmedAmountKobo === $purchase->amount_kobo);
        }

        $stored = PurchaseIdentityRecipient::where('purchase_id', $purchase->id)->value('keyed_fingerprint');
        foreach ($fingerprints as $fingerprint) {
            if ($stored !== null && hash_equals($stored, $fingerprint)) {
                return true;
            }
        }

        return false;
    }
}
