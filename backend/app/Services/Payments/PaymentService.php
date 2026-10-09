<?php

namespace App\Services\Payments;

use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\MalformedWebhook;
use App\Exceptions\Payments\PaymentException;
use App\Exceptions\Wallet\WalletException;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PaymentStatusChange;
use App\Models\PaymentWebhook;
use App\Models\SystemUser;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\Data\WebhookResult;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use App\Support\Payments\GatewayPaymentStatus;
use App\Support\Payments\PaymentLimits;
use App\Support\Payments\PaymentSource;
use App\Support\Payments\PaymentStatus;
use App\Support\Payments\WebhookOutcome;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code that creates and settles payments. The money path is always:
 * PAY reference -> server-side verification with the gateway -> exact amount
 * and currency -> WalletService::credit() (one funding transaction and one
 * ledger entry) -> payment successful, all in one database transaction.
 *
 * - A webhook or a browser return only triggers verification; its claims are
 *   never trusted.
 * - Gateway HTTP calls happen outside any database transaction; the payment
 *   row is locked (SELECT ... FOR UPDATE) only to apply the verified result,
 *   so parallel webhooks, returns and reconciliation settle it exactly once.
 * - Duplicate credits are impossible: the locked status check, the
 *   'payment:{reference}' wallet idempotency key and the unique
 *   payments.wallet_transaction_id all stand in the way.
 * - The payment never touches the wallet balance itself.
 * Authorization for staff actions is checked by the calling admin actions.
 */
class PaymentService
{
    public function __construct(private GatewayRegistry $gateways, private WalletService $wallets) {}

    /**
     * Creates a pending wallet-funding payment. A repeated idempotency key
     * returns the same payment (a key reused for another amount or gateway is refused).
     *
     * @throws PaymentException
     */
    public function create(User $user, int $amountKobo, PaymentGateway $gateway, string $idempotencyKey): Payment
    {
        $min = PaymentLimits::minFundingKobo();
        $max = PaymentLimits::maxFundingKobo();
        if ($amountKobo < $min || $amountKobo > $max) {
            throw new PaymentException('Enter an amount from '.Money::format($min).' to '.Money::format($max).'.');
        }
        if ($existing = $this->existing($user, $idempotencyKey, $amountKobo, $gateway)) {
            return $existing;
        }
        if (! $this->gateways->usableForFunding()->contains('id', $gateway->id)) {
            throw new PaymentException('This payment method is not available right now.');
        }

        $wallet = $this->wallets->walletFor($user);

        try {
            return DB::transaction(function () use ($user, $wallet, $amountKobo, $gateway, $idempotencyKey) {
                $payment = (new Payment)->forceFill([
                    'reference' => WalletService::reference('PAY'),
                    'user_id' => $user->id,
                    'wallet_id' => $wallet->id,
                    'payment_gateway_id' => $gateway->id,
                    'mode' => $gateway->mode,
                    'amount_kobo' => $amountKobo,
                    'currency' => 'NGN',
                    'status' => PaymentStatus::Pending,
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => now()->addMinutes(PaymentLimits::pendingExpiryMinutes()),
                ]);
                $payment->save();
                $this->record($payment, null, PaymentStatus::Pending, PaymentSource::Customer);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            // A parallel request with the same key won the race.
            return $this->existing($user, $idempotencyKey, $amountKobo, $gateway) ?? throw $e;
        }
    }

    /**
     * Starts checkout with the gateway (once per payment). The checkout URL
     * must be https on a host the adapter declared. A failed start fails the
     * payment; nothing is retried automatically unless the adapter declares
     * initialization idempotent.
     */
    public function initialize(Payment $payment, string $returnUrl): Payment
    {
        $lock = Cache::lock('payments:init:'.$payment->reference, 60);
        if (! $lock->get()) {
            return $payment->fresh(); // another request is starting this checkout
        }

        try {
            $payment = $payment->fresh();
            if ($payment->status !== PaymentStatus::Pending || $payment->checkout_url !== null) {
                return $payment;
            }

            try {
                $gateway = $payment->gateway;
                $context = $this->gateways->contextFor($gateway, $payment->mode);
                $adapter = $this->gateways->adapterFor($gateway);
                $attempts = $adapter->initializeIsIdempotent() ? 2 : 1;
                for ($i = 1; ; $i++) {
                    try {
                        $result = $adapter->initialize($payment, $returnUrl, $context);
                        break;
                    } catch (GatewayException $e) {
                        if ($i >= $attempts) {
                            throw $e;
                        }
                    }
                }
                self::assertCheckoutUrl($result->checkoutUrl, $adapter->checkoutHosts($payment->mode));
            } catch (GatewayException $e) {
                return $this->fail($payment, 'Checkout could not be started: '.$e->getMessage(), PaymentSource::Customer);
            }

            return DB::transaction(function () use ($payment, $result) {
                $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === PaymentStatus::Pending && $locked->checkout_url === null) {
                    $locked->forceFill(['gateway_reference' => $result->gatewayReference, 'checkout_url' => $result->checkoutUrl])->save();
                }

                return $locked;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Asks the gateway (server to server) what happened and applies the
     * answer exactly once. Automatic sources settle pending payments (and
     * move a late-paid failed payment to review); only staff (Admin) can
     * settle a payment that is in review. Returns the current payment.
     *
     * @throws GatewayException when the gateway cannot be asked; nothing changes
     */
    public function verifyAndFinalize(Payment $payment, PaymentSource $source, ?SystemUser $actor = null): Payment
    {
        $payment = $payment->fresh();
        $settleable = match ($payment->status) {
            PaymentStatus::Pending => true,
            PaymentStatus::Review => $source === PaymentSource::Admin,
            PaymentStatus::Failed => in_array($source, [PaymentSource::Webhook, PaymentSource::Admin], true),
            PaymentStatus::Successful => false,
        };
        if (! $settleable) {
            return $payment;
        }

        if ($payment->checkout_url === null && $payment->gateway_reference === null) {
            // Checkout never started, so there is nothing the gateway could confirm.
            return $this->pastGrace($payment)
                ? $this->fail($payment, 'Checkout was never started.', $source, $actor)
                : $payment;
        }

        $context = $this->gateways->contextFor($payment->gateway, $payment->mode);
        $result = $this->gateways->adapterFor($payment->gateway)->verify($payment, $context); // no database lock held here

        return $this->apply($payment, $result, $source, $actor);
    }

    /** Staff closes a payment in review without crediting it (payments.manage, checked by the caller). */
    public function closeReview(Payment $payment, string $note, SystemUser $actor): Payment
    {
        return DB::transaction(function () use ($payment, $note, $actor) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentStatus::Review) {
                throw new PaymentException('Only payments in review can be closed.');
            }

            return $this->transition($locked, PaymentStatus::Failed, PaymentSource::Admin, 'Closed by staff after review.', $actor, $note);
        });
    }

    /**
     * Handles one inbound webhook: size check, adapter authentication,
     * parsing, duplicate protection (unique gateway + event key), then
     * server-side verification. Only a sanitized payload is stored.
     */
    public function handleWebhook(string $gatewayCode, WebhookRequest $request): WebhookResult
    {
        $gateway = PaymentGateway::with('credentials')->where('code', $gatewayCode)->first();
        if ($gateway === null) {
            return new WebhookResult(404, null);
        }
        if (strlen($request->body) > config('payments.webhooks.max_bytes')) {
            return new WebhookResult(413, null);
        }

        $adapter = $this->gateways->adapterFor($gateway);
        if ($adapter === null || ! $this->gateways->isConfigured($gateway)) {
            $this->storeRejected($gateway, WebhookOutcome::NotConfigured, false);

            return new WebhookResult(503, WebhookOutcome::NotConfigured);
        }
        if (! $adapter->authenticateWebhook($request, $this->gateways->contextFor($gateway))) {
            $this->storeRejected($gateway, WebhookOutcome::InvalidSignature, false);

            return new WebhookResult(401, WebhookOutcome::InvalidSignature);
        }

        try {
            $event = $adapter->parseWebhook($request);
        } catch (MalformedWebhook) {
            $this->storeRejected($gateway, WebhookOutcome::Malformed, true);

            return new WebhookResult(400, WebhookOutcome::Malformed);
        }

        $payment = $this->findPayment($gateway, $event->paymentReference, $event->gatewayReference);
        $payload = json_encode($event->sanitizedPayload);
        try {
            $webhook = (new PaymentWebhook)->forceFill([
                'payment_gateway_id' => $gateway->id,
                'event_key' => mb_substr($event->eventKey, 0, 191),
                'payment_id' => $payment?->id,
                'signature_valid' => true,
                'outcome' => $payment ? WebhookOutcome::Processed : WebhookOutcome::UnknownPayment,
                'payload' => $payload !== false && strlen($payload) <= config('payments.webhooks.max_bytes') ? $event->sanitizedPayload : null,
                'received_at' => now(),
            ]);
            $webhook->save();
        } catch (UniqueConstraintViolationException) {
            return new WebhookResult(200, null, duplicate: true); // already received: never processed twice
        }

        if ($payment !== null) {
            try {
                $this->verifyAndFinalize($payment, PaymentSource::Webhook);
            } catch (GatewayException) {
                // Verification unavailable right now; reconciliation will check again.
            }
        }
        $webhook->forceFill(['processed_at' => now()])->save();

        return new WebhookResult(200, $webhook->outcome);
    }

    /**
     * Rechecks pending payments older than the minimum age, oldest check
     * first, in small batches. Never fails a payment just because the
     * customer did not come back: an expired payment fails only after the
     * grace period AND a gateway check that still finds it unpaid.
     *
     * @return array{checked: int, settled: int, unavailable: int}
     */
    public function reconcile(): array
    {
        $stats = ['checked' => 0, 'settled' => 0, 'unavailable' => 0];
        $due = Payment::where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes(config('payments.reconcile_min_age_minutes')))
            ->orderBy('updated_at')->orderBy('id')->limit(config('payments.reconcile_batch'))->get();

        foreach ($due as $payment) {
            $stats['checked']++;
            try {
                $after = $this->verifyAndFinalize($payment, PaymentSource::Reconcile);
                if ($after->status !== PaymentStatus::Pending) {
                    $stats['settled']++;

                    continue;
                }
            } catch (GatewayException) {
                $stats['unavailable']++;
            }
            $payment->fresh()->touch(); // checked: move to the back of the queue
        }

        return $stats;
    }

    /** Clears stored webhook payloads after the retention period; the outcome rows stay. Returns the number cleared. */
    public function prunePayloads(): int
    {
        return PaymentWebhook::whereNotNull('payload')
            ->where('received_at', '<', now()->subDays(config('payments.webhooks.payload_retention_days')))
            ->update(['payload' => null]);
    }

    /** Applies a verification result under the payment row lock. */
    private function apply(Payment $payment, VerificationResult $result, PaymentSource $source, ?SystemUser $actor): Payment
    {
        return DB::transaction(function () use ($payment, $result, $source, $actor) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $status = $locked->status;
            if ($status === PaymentStatus::Successful) {
                return $locked; // settled by a parallel request
            }

            if ($result->status === GatewayPaymentStatus::Paid) {
                $locked->forceFill(['verified_amount_kobo' => $result->amountKobo, 'verified_at' => now()]);
                $mismatch = $this->mismatch($locked, $result);

                if ($mismatch === null && ($status === PaymentStatus::Pending || ($status === PaymentStatus::Review && $source === PaymentSource::Admin))) {
                    return $this->credit($locked, $source, $actor);
                }
                if ($status === PaymentStatus::Pending) {
                    return $this->transition($locked, PaymentStatus::Review, $source, $mismatch, $actor);
                }
                if ($status === PaymentStatus::Failed) {
                    return $this->transition($locked, PaymentStatus::Review, $source,
                        'The gateway confirmed payment after this payment had failed.'.($mismatch ? ' '.$mismatch : ''), $actor);
                }
                $locked->save(); // in review: keep the latest verified figures

                return $locked;
            }

            if ($status !== PaymentStatus::Pending) {
                return $locked; // review and failed payments only change on a confirmed payment
            }
            if ($result->status === GatewayPaymentStatus::Failed) {
                return $this->transition($locked, PaymentStatus::Failed, $source, 'The gateway reports the payment failed.', $actor);
            }
            if ($this->pastGrace($locked)) {
                return $this->transition($locked, PaymentStatus::Failed, $source, 'Not paid before the payment expired.', $actor);
            }

            return $locked;
        }, 3);
    }

    /** Credits the wallet and marks the payment successful in the current transaction; a refused credit moves it to review instead. */
    private function credit(Payment $locked, PaymentSource $source, ?SystemUser $actor): Payment
    {
        try {
            $result = $this->wallets->credit(Wallet::findOrFail($locked->wallet_id), $locked->amount_kobo, LedgerEntryType::Funding,
                TransactionType::Funding, 'Wallet funding', 'payment:'.$locked->reference, null, ['payment' => $locked->reference]);
        } catch (WalletException $e) {
            // WalletService rolled its own work back (savepoint): no partial financial state.
            return $this->transition($locked, PaymentStatus::Review, $source, 'Verified, but the wallet credit was refused: '.$e->getMessage(), $actor);
        }

        $locked->forceFill(['wallet_transaction_id' => $result->transaction->id]);

        return $this->transition($locked, PaymentStatus::Successful, $source, null, $actor);
    }

    private function fail(Payment $payment, string $reason, PaymentSource $source, ?SystemUser $actor = null): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $source, $actor) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            return $locked->status === PaymentStatus::Pending
                ? $this->transition($locked, PaymentStatus::Failed, $source, $reason, $actor)
                : $locked;
        });
    }

    private function transition(Payment $locked, PaymentStatus $to, PaymentSource $source, ?string $reason, ?SystemUser $actor, ?string $note = null): Payment
    {
        $from = $locked->status;
        $locked->forceFill([
            'status' => $to,
            'failure_reason' => $to === PaymentStatus::Successful ? null : ($reason !== null ? Str::limit($reason, 250) : $locked->failure_reason),
            'completed_at' => in_array($to, [PaymentStatus::Successful, PaymentStatus::Failed], true) ? now() : null,
        ])->save();
        $this->record($locked, $from, $to, $source, $actor, $note ?? $reason);

        return $locked;
    }

    private function record(Payment $payment, ?PaymentStatus $from, PaymentStatus $to, PaymentSource $source, ?SystemUser $actor = null, ?string $note = null): void
    {
        (new PaymentStatusChange)->forceFill([
            'payment_id' => $payment->id,
            'old_status' => $from,
            'new_status' => $to,
            'source' => $source,
            'changed_by' => $actor?->id,
            'note' => $note !== null ? Str::limit($note, 250) : null,
        ])->save();
    }

    /** Why a confirmed payment cannot be credited automatically, or null when it matches exactly. */
    private function mismatch(Payment $payment, VerificationResult $result): ?string
    {
        if ($result->amountKobo !== $payment->amount_kobo) {
            return 'Amount mismatch: the gateway reports '.($result->amountKobo === null ? 'no amount' : Money::format($result->amountKobo))
                .', expected '.Money::format($payment->amount_kobo).'.';
        }
        if (strtoupper((string) $result->currency) !== $payment->currency) {
            return 'Currency mismatch: the gateway reports '.($result->currency ?: 'no currency').', expected '.$payment->currency.'.';
        }
        if ($result->gatewayReference !== null && $payment->gateway_reference !== null && $result->gatewayReference !== $payment->gateway_reference) {
            return 'Gateway reference mismatch.';
        }

        return null;
    }

    private function pastGrace(Payment $payment): bool
    {
        return $payment->expires_at->copy()->addMinutes(config('payments.expiry_grace_minutes'))->isPast();
    }

    private function existing(User $user, string $key, int $amountKobo, PaymentGateway $gateway): ?Payment
    {
        $payment = Payment::where('user_id', $user->id)->where('idempotency_key', $key)->first();
        if ($payment !== null && ($payment->amount_kobo !== $amountKobo || $payment->payment_gateway_id !== $gateway->id)) {
            throw new PaymentException('This form was already used for a different payment. Please start again.');
        }

        return $payment;
    }

    private function findPayment(PaymentGateway $gateway, ?string $reference, ?string $gatewayReference): ?Payment
    {
        $query = Payment::where('payment_gateway_id', $gateway->id);
        if ($reference !== null && ($payment = (clone $query)->where('reference', $reference)->first())) {
            return $payment;
        }

        return $gatewayReference !== null ? $query->where('gateway_reference', $gatewayReference)->first() : null;
    }

    /** Unauthenticated or unusable events are recorded without their payload under a one-off key. */
    private function storeRejected(PaymentGateway $gateway, WebhookOutcome $outcome, bool $signatureValid): void
    {
        (new PaymentWebhook)->forceFill([
            'payment_gateway_id' => $gateway->id,
            'event_key' => 'rejected:'.Str::uuid(),
            'signature_valid' => $signatureValid,
            'outcome' => $outcome,
            'payload' => null,
            'received_at' => now(),
            'processed_at' => now(),
        ])->save();
    }

    /** @param  list<string>  $hosts */
    public static function assertCheckoutUrl(string $url, array $hosts): void
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! in_array(strtolower($parts['host'] ?? ''), $hosts, true)) {
            throw new GatewayException('The gateway returned a checkout address that is not allowed.');
        }
    }
}
