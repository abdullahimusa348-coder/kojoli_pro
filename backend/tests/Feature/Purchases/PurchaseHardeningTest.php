<?php

use App\Exceptions\Purchases\PurchaseException;
use App\Models\Plan;
use App\Models\PlanProviderRoute;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Purchase;
use App\Models\PurchaseAttempt;
use App\Models\Service;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Support\Phone\NigerianPhone;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Phase 10 Step 1, CP1 hardening: the nine invariants required before any
 * code may debit a wallet or execute a purchase. Model guards, plus the
 * composite foreign keys that catch bypasses through raw query updates.
 */

function phPlan(): Plan
{
    $service = Service::factory()->create(['name' => 'Airtime', 'slug' => 'airtime-'.Str::lower(Str::random(5))]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'MTN', 'code' => 'airtime-mtn-'.Str::lower(Str::random(5)), 'network' => 'mtn']);

    return Plan::factory()->create(['product_id' => $product->id, 'name' => 'Airtime', 'code' => $product->code.'-a']);
}

function phPurchase(?Plan $plan = null, ?User $user = null, array $attributes = []): Purchase
{
    $plan ??= phPlan();
    $user ??= User::factory()->create();
    $wallet = app(WalletService::class)->walletFor($user);
    $recipient = $attributes['recipient'] ?? '08012345678';
    $face = array_key_exists('face_value_kobo', $attributes) ? $attributes['face_value_kobo'] : 50_000;

    return tap((new Purchase)->forceFill($attributes + [
        'reference' => WalletService::reference('PUR'), 'user_id' => $user->id, 'wallet_id' => $wallet->id, 'plan_id' => $plan->id,
        'service_id' => $plan->product->service_id, 'service_name' => 'Airtime', 'product_name' => 'MTN', 'plan_name' => 'Airtime',
        'network' => 'mtn', 'user_type' => $user->user_type, 'amount_type' => 'variable', 'recipient' => $recipient,
        'face_value_kobo' => $face, 'amount_kobo' => 50_000, 'status' => PurchaseStatus::Pending, 'idempotency_key' => (string) Str::uuid(),
        'request_fingerprint' => Purchase::fingerprint($plan->id, $recipient, $face),
    ]))->save();
}

function phRoute(Plan $plan, int $priority = 1, array $cost = ['cost_type' => 'fixed', 'cost_kobo' => 48_000]): PlanProviderRoute
{
    $provider = Provider::factory()->create(['name' => 'P '.Str::random(5), 'code' => 'p-'.Str::lower(Str::random(8))]);

    return tap((new PlanProviderRoute)->forceFill($cost + ['plan_id' => $plan->id, 'provider_id' => $provider->id, 'priority' => $priority,
        'provider_plan_code' => 'C'.$priority, 'is_active' => true]))->save();
}

function phAttempt(Purchase $purchase, PlanProviderRoute $route, int $number = 1, array $attributes = []): PurchaseAttempt
{
    return tap((new PurchaseAttempt)->forceFill($attributes + [
        'purchase_id' => $purchase->id, 'attempt_number' => $number, 'plan_provider_route_id' => $route->id, 'provider_id' => $route->provider_id,
        'route_priority' => $route->priority, 'provider_plan_code' => $route->provider_plan_code, 'cost_type' => $route->cost_type,
        'cost_kobo' => $route->cost_kobo, 'cost_discount_bps' => $route->cost_discount_bps,
        'request_reference' => WalletService::reference('PRA'), 'status' => PurchaseAttemptStatus::Started, 'started_at' => now(),
    ]))->save();
}

/** A purchase wallet transaction on the given wallet (defaults: the purchase's debit). */
function phTx(Purchase $purchase, string $direction = 'debit', ?int $amount = null, ?TransactionType $type = null, ?User $owner = null): int
{
    $wallets = app(WalletService::class);
    $wallet = $wallets->walletFor($owner ?? $purchase->user);
    $amount ??= $purchase->amount_kobo;
    $type ??= TransactionType::Purchase;
    $key = $direction.'-'.Str::uuid();
    if ($direction === 'debit') {
        $wallets->credit($wallet, $amount, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');

        return $wallets->debit($wallet, $amount, LedgerEntryType::PurchaseDebit, $type, 'Purchase', $key)->transaction->id;
    }

    return $wallets->credit($wallet, $amount, LedgerEntryType::PurchaseRefund, $type, 'Purchase refund', $key)->transaction->id;
}

function phDebited(?Plan $plan = null): Purchase
{
    $purchase = phPurchase($plan);
    $purchase->forceFill(['debit_transaction_id' => phTx($purchase)])->save();

    return $purchase->fresh();
}

function phSucceeded(Purchase $purchase, ?PlanProviderRoute $route = null): PurchaseAttempt
{
    $attempt = phAttempt($purchase, $route ?? phRoute($purchase->plan));
    $attempt->forceFill(['status' => PurchaseAttemptStatus::Succeeded, 'provider_reference' => 'P-'.Str::random(6), 'finished_at' => now()])->save();

    return $attempt->fresh();
}

function phSuccessful(): Purchase
{
    $purchase = phDebited();
    $attempt = phSucceeded($purchase);
    $purchase->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id, 'cost_kobo' => 48_000, 'margin_kobo' => 2_000,
        'completed_at' => now()])->save();

    return $purchase->fresh();
}

function phFailed(): Purchase
{
    $purchase = phDebited();
    $purchase->forceFill(['status' => PurchaseStatus::Failed, 'refund_transaction_id' => phTx($purchase, 'credit'), 'failure_reason' => 'Declined.',
        'completed_at' => now()])->save();

    return $purchase->fresh();
}

describe('1-2: success and failure require the recorded debit', function () {
    it('rejects failed without a debit, even with a refund', function () {
        $purchase = phPurchase();

        expect(fn () => $purchase->forceFill(['status' => PurchaseStatus::Failed, 'refund_transaction_id' => phTx($purchase, 'credit')])->save())
            ->toThrow(PurchaseException::class, 'after its debit is recorded');
    });

    it('rejects successful without a debit', function () {
        $purchase = phPurchase();
        $attempt = phSucceeded($purchase);

        expect(fn () => $purchase->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id,
            'cost_kobo' => 48_000, 'margin_kobo' => 2_000])->save())->toThrow(PurchaseException::class, 'after its debit is recorded');
    });

    it('accepts failure and success once the debit is recorded', function () {
        expect(phFailed()->status)->toBe(PurchaseStatus::Failed)->and(phSuccessful()->status)->toBe(PurchaseStatus::Successful);
    });

    it('only accepts a debit that is a purchase debit of the charged amount on the same wallet', function (Closure $tx) {
        $purchase = phPurchase();

        expect(fn () => $purchase->forceFill(['debit_transaction_id' => $tx($purchase)])->save())->toThrow(PurchaseException::class);
    })->with([
        'wrong amount' => fn ($p) => phTx($p, 'debit', 49_999),
        'wrong type' => fn ($p) => phTx($p, 'debit', null, TransactionType::Adjustment),
        'a credit' => fn ($p) => phTx($p, 'credit'),
        'another wallet' => fn ($p) => phTx($p, 'debit', null, null, User::factory()->create()),
    ]);
});

describe('refund integrity', function () {
    it('rejects a refund that is not a purchase credit of the charged amount on the same wallet', function (Closure $tx) {
        $purchase = phDebited();

        expect(fn () => $purchase->forceFill(['status' => PurchaseStatus::Failed, 'refund_transaction_id' => $tx($purchase)])->save())
            ->toThrow(PurchaseException::class);
    })->with([
        'wrong wallet' => fn ($p) => phTx($p, 'credit', null, null, User::factory()->create()),
        'wrong amount' => fn ($p) => phTx($p, 'credit', 49_000),
        'a debit' => fn ($p) => phTx($p, 'debit'),
        'wrong type' => fn ($p) => phTx($p, 'credit', null, TransactionType::Adjustment),
        'the debit itself' => fn ($p) => $p->debit_transaction_id,
    ]);

    it('blocks a wrong-wallet debit or refund written around the model', function (string $column) {
        $purchase = phDebited();
        $foreign = phTx($purchase, $column === 'debit_transaction_id' ? 'debit' : 'credit', null, null, User::factory()->create());

        expect(fn () => DB::table('purchases')->where('id', $purchase->id)->update([$column => $foreign]))->toThrow(QueryException::class);
    })->with(['debit_transaction_id', 'refund_transaction_id']);
});

describe('3: the delivering attempt', function () {
    it('must belong to the same purchase', function () {
        $purchase = phDebited();
        $other = phDebited($purchase->plan);
        $foreign = phSucceeded($other);

        expect(fn () => $purchase->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $foreign->id,
            'cost_kobo' => 48_000, 'margin_kobo' => 2_000])->save())->toThrow(PurchaseException::class, 'succeeded attempt of this purchase');
    });

    it('must belong to the same purchase even when written around the model', function () {
        $purchase = phDebited();
        $foreign = phSucceeded(phDebited($purchase->plan));

        expect(fn () => DB::table('purchases')->where('id', $purchase->id)->update(['successful_attempt_id' => $foreign->id]))->toThrow(QueryException::class);
    });

    it('must have succeeded', function (?PurchaseAttemptStatus $status) {
        $purchase = phDebited();
        $attempt = phAttempt($purchase, phRoute($purchase->plan));
        if ($status !== null) {
            $attempt->forceFill(['status' => $status])->save();
        }

        expect(fn () => $purchase->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id,
            'cost_kobo' => 48_000, 'margin_kobo' => 2_000])->save())->toThrow(PurchaseException::class, 'succeeded attempt');
    })->with(['started' => [null], 'unknown' => [PurchaseAttemptStatus::Unknown], 'failed_definite' => [PurchaseAttemptStatus::FailedDefinite]]);
});

describe('4: successful and failed purchases are fully immutable', function () {
    it('freezes a successful purchase', function (array $change) {
        $purchase = phSuccessful();

        expect(fn () => $purchase->forceFill($change)->save())->toThrow(LogicException::class, 'can no longer change');
    })->with([
        'status' => [['status' => PurchaseStatus::Failed]], 'cost' => [['cost_kobo' => 1]], 'margin' => [['margin_kobo' => 1]],
        'failure reason' => [['failure_reason' => 'x']], 'check count' => [['check_count' => 9]], 'next check' => [['next_check_at' => now()]],
        'completed at' => [['completed_at' => now()->addDay()]], 'amount' => [['amount_kobo' => 1]], 'reference' => [['reference' => 'PUR-X']],
    ]);

    it('freezes a failed purchase', function (array $change) {
        $purchase = phFailed();

        expect(fn () => $purchase->forceFill($change)->save())->toThrow(LogicException::class, 'can no longer change');
    })->with([
        'status' => [['status' => PurchaseStatus::Successful]], 'failure reason' => [['failure_reason' => 'Changed.']],
        'check count' => [['check_count' => 2]], 'completed at' => [['completed_at' => now()->addDay()]], 'recipient' => [['recipient' => '08099999999']],
    ]);

    it('does not even allow touching a final purchase', function () {
        $successful = phSuccessful();
        $failed = phFailed();
        $this->travel(5)->seconds(); // so touch() really changes updated_at

        expect(fn () => $successful->touch())->toThrow(LogicException::class)
            ->and(fn () => $failed->touch())->toThrow(LogicException::class);
    });

    it('keeps pending and review purchases workable under the approved rules', function () {
        $purchase = phDebited();
        $purchase->forceFill(['check_count' => 1, 'next_check_at' => now()->addMinutes(2)])->save();
        $purchase->fresh()->forceFill(['status' => PurchaseStatus::Review, 'failure_reason' => 'No definite outcome after 24 hours.'])->save();
        $purchase->fresh()->forceFill(['check_count' => 2])->save();

        expect($purchase->fresh()->status)->toBe(PurchaseStatus::Review)->and($purchase->fresh()->check_count)->toBe(2);
    });
});

describe('5: cost and margin are set atomically with success', function () {
    it('rejects cost or margin outside the success save', function (array $values) {
        $purchase = phDebited();

        expect(fn () => $purchase->forceFill($values)->save())->toThrow(PurchaseException::class);
    })->with(['cost' => [['cost_kobo' => 48_000]], 'margin' => [['margin_kobo' => 2_000]]]);

    it('requires cost to be the delivering route snapshot and margin the charged amount minus it', function (array $values) {
        $purchase = phDebited();
        $attempt = phSucceeded($purchase);

        expect(fn () => $purchase->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id] + $values)->save())
            ->toThrow(PurchaseException::class, 'Cost and margin');
    })->with([
        'missing both' => [[]], 'missing margin' => [['cost_kobo' => 48_000]], 'wrong cost' => [['cost_kobo' => 40_000, 'margin_kobo' => 10_000]],
        'wrong margin' => [['cost_kobo' => 48_000, 'margin_kobo' => 1]],
    ]);

    it('computes a percent cost from the face value and leaves cost and margin null without a route cost', function () {
        $percent = phDebited();
        $attempt = phSucceeded($percent, phRoute($percent->plan, 1, ['cost_type' => 'percent', 'cost_discount_bps' => 250]));
        $percent->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id, 'cost_kobo' => 48_750, 'margin_kobo' => 1_250])->save();

        $free = phDebited();
        $attempt = phSucceeded($free, phRoute($free->plan, 1, ['cost_type' => null]));
        $free->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id])->save();

        expect($percent->fresh()->margin_kobo)->toBe(1_250)->and($free->fresh()->cost_kobo)->toBeNull()->and($free->fresh()->margin_kobo)->toBeNull();
    });

    it('allows a negative margin when the cost exceeds the charged amount', function () {
        $purchase = phDebited();
        $attempt = phSucceeded($purchase, phRoute($purchase->plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 51_000]));
        $purchase->forceFill(['status' => PurchaseStatus::Successful, 'successful_attempt_id' => $attempt->id, 'cost_kobo' => 51_000, 'margin_kobo' => -1_000])->save();

        expect($purchase->fresh()->margin_kobo)->toBe(-1_000);
    });
});

describe('6: definite attempts are immutable; unknown attempts stay re-checkable', function () {
    it('freezes succeeded and failed_definite attempts', function (PurchaseAttemptStatus $status, array $change) {
        $purchase = phDebited();
        $attempt = phAttempt($purchase, phRoute($purchase->plan));
        $attempt->forceFill(['status' => $status])->save();

        expect(fn () => $attempt->fresh()->forceFill($change)->save())->toThrow(LogicException::class, 'can no longer change');
    })->with([
        [PurchaseAttemptStatus::Succeeded, ['error_message' => 'x']],
        [PurchaseAttemptStatus::Succeeded, ['last_checked_at' => now()]],
        [PurchaseAttemptStatus::Succeeded, ['provider_reference' => 'P-NEW']],
        [PurchaseAttemptStatus::FailedDefinite, ['status' => PurchaseAttemptStatus::Succeeded]],
        [PurchaseAttemptStatus::FailedDefinite, ['finished_at' => now()->addHour()]],
        [PurchaseAttemptStatus::FailedDefinite, ['error_code' => 'changed']],
    ]);

    it('keeps an unknown attempt open for provider re-checks until a definite outcome', function () {
        $purchase = phDebited();
        $attempt = phAttempt($purchase, phRoute($purchase->plan));
        $attempt->forceFill(['status' => PurchaseAttemptStatus::Unknown, 'error_code' => 'no_response', 'finished_at' => now()])->save();
        $attempt->fresh()->forceFill(['last_checked_at' => now(), 'error_message' => 'Still processing.'])->save();
        $attempt->fresh()->forceFill(['provider_reference' => 'P-LATE', 'last_checked_at' => now()->addMinutes(5)])->save();
        $attempt->fresh()->forceFill(['status' => PurchaseAttemptStatus::Succeeded])->save();

        expect($attempt->fresh()->status)->toBe(PurchaseAttemptStatus::Succeeded)->and($attempt->fresh()->provider_reference)->toBe('P-LATE')
            ->and(fn () => $attempt->fresh()->forceFill(['last_checked_at' => now()->addDay()])->save())->toThrow(LogicException::class);
    });
});

describe('7-8: attempt route and provider integrity', function () {
    it('rejects a route from another plan', function () {
        $purchase = phDebited();

        expect(fn () => phAttempt($purchase, phRoute(phPlan())))->toThrow(PurchaseException::class, 'belong to the purchase plan');
    });

    it('rejects a provider that is not the route provider', function () {
        $purchase = phDebited();
        $route = phRoute($purchase->plan);
        $otherProvider = phRoute(phPlan())->provider_id;

        expect(fn () => phAttempt($purchase, $route, 1, ['provider_id' => $otherProvider]))->toThrow(PurchaseException::class, 'route provider');
    });

    it('rejects an attempt whose snapshot differs from its route', function (array $change) {
        $purchase = phDebited();

        expect(fn () => phAttempt($purchase, phRoute($purchase->plan), 1, $change))->toThrow(PurchaseException::class, 'exact snapshot');
    })->with([[['route_priority' => 9]], [['provider_plan_code' => 'OTHER']], [['cost_kobo' => 1]], [['cost_type' => null]]]);

    it('records the purchase plan on the attempt and refuses another plan id', function () {
        $purchase = phDebited();
        $attempt = phAttempt($purchase, phRoute($purchase->plan));

        expect($attempt->fresh()->plan_id)->toBe($purchase->plan_id)
            ->and(fn () => phAttempt($purchase, phRoute($purchase->plan, 2), 2, ['plan_id' => phPlan()->id]))->toThrow(PurchaseException::class);
    });

    it('only allows attempts for a pending purchase', function () {
        $failed = phFailed();

        expect(fn () => phAttempt($failed, phRoute($failed->plan)))->toThrow(PurchaseException::class, 'pending purchase');
    });

    it('blocks a foreign route or provider written around the model', function (string $column) {
        $purchase = phDebited();
        $attempt = phAttempt($purchase, phRoute($purchase->plan));
        $foreign = phRoute(phPlan());

        expect(fn () => DB::table('purchase_attempts')->where('id', $attempt->id)->update([$column => $column === 'provider_id' ? $foreign->provider_id : $foreign->id]))
            ->toThrow(QueryException::class);
    })->with(['provider_id', 'plan_provider_route_id']);
});

describe('9: phone normalization before fingerprinting', function () {
    it('normalizes the accepted Nigerian formats to the 11-digit local form', function (string $input) {
        expect(NigerianPhone::normalize($input))->toBe('08012345678');
    })->with(['08012345678', '+2348012345678', '+234 801 234 5678', '0801-234-5678', '(0801) 234.5678']);

    it('rejects other formats and does not validate network prefixes', function () {
        foreach (['2348012345678', '8012345678', '080123456789', '+23480123456', '+44 7700 900123', 'abc', ''] as $input) {
            expect(NigerianPhone::normalize($input))->toBeNull("accepted {$input}");
        }
        expect(NigerianPhone::normalize('01112345678'))->toBe('01112345678'); // no prefix rule
    });

    it('accepts the valid local and +234 formats, including formatted ones', function () {
        expect(NigerianPhone::normalize('08012345678'))->toBe('08012345678')
            ->and(NigerianPhone::normalize('+2348012345678'))->toBe('08012345678')
            ->and(NigerianPhone::normalize('+234 701 234 5678'))->toBe('07012345678')
            ->and(NigerianPhone::normalize('+234-(901)-234.5678'))->toBe('09012345678')
            ->and(NigerianPhone::normalize(' 0811 234 5678 '))->toBe('08112345678');
    });

    it('rejects +234 numbers whose first digit after 234 is 0', function (string $input) {
        expect(NigerianPhone::normalize($input))->toBeNull()
            ->and(fn () => Purchase::fingerprint(1, $input, null))->toThrow(InvalidArgumentException::class);
    })->with(['+2340801234567', '+234 080 123 4567', '+2340000000000', '+234-0-801234567']);

    it('rejects invalid digit counts', function (string $input) {
        expect(NigerianPhone::normalize($input))->toBeNull();
    })->with(['0801234567', '080123456789', '+234801234567', '+23480123456789', '0', '+234']);

    it('gives equivalent formats the same fingerprint', function () {
        $fingerprint = Purchase::fingerprint(7, '08012345678', 10_000);
        expect(Purchase::fingerprint(7, '+2348012345678', null))->toBe(Purchase::fingerprint(7, '08012345678', null))
            ->and(Purchase::fingerprint(7, '+234 901 234 5678', 500))->toBe(Purchase::fingerprint(7, '0901-234-5678', 500));

        expect(Purchase::fingerprint(7, '+2348012345678', 10_000))->toBe($fingerprint)
            ->and(Purchase::fingerprint(7, '+234 801 234 5678', 10_000))->toBe($fingerprint)
            ->and(Purchase::fingerprint(7, '08012345679', 10_000))->not->toBe($fingerprint);
    });

    it('fingerprints only normalized numbers and refuses invalid ones', function () {
        expect(Purchase::fingerprint(7, '+2348012345678', null))->toBe(hash('sha256', '7|08012345678|-'))
            ->and(fn () => Purchase::fingerprint(7, '12345', null))->toThrow(InvalidArgumentException::class);
    });

    it('stores only canonical recipients with a matching fingerprint', function () {
        expect(fn () => phPurchase(null, null, ['recipient' => '+2348012345678', 'request_fingerprint' => str_repeat('a', 64)]))
            ->toThrow(PurchaseException::class, 'canonical')
            ->and(fn () => phPurchase(null, null, ['request_fingerprint' => Purchase::fingerprint(1, '08099999999', null)]))
            ->toThrow(PurchaseException::class, 'fingerprint');
    });
});
