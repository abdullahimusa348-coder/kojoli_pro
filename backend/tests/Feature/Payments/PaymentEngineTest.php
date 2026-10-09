<?php

use App\Exceptions\Payments\GatewayException;
use App\Exceptions\Payments\GatewayNotConfigured;
use App\Exceptions\Payments\PaymentException;
use App\Models\Payment;
use App\Models\PaymentStatusChange;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Payments\GatewayRegistry;
use App\Services\Wallet\WalletService;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\GatewayStatus;
use App\Support\Payments\PaymentSource;
use App\Support\Payments\PaymentStatus;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Payments\FakeGateway;

require_once __DIR__.'/../../Support/Payments/helpers.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
    payDrivers();
    Http::preventStrayRequests();
});

/** Exactly one funding transaction and ledger entry for the payment, and a consistent wallet. */
function payAssertCreditedOnce(Payment $payment, int $expectedBalance): void
{
    $payment->refresh();
    $tx = Transaction::where('idempotency_key', 'payment:'.$payment->reference)->get();

    expect($payment->status)->toBe(PaymentStatus::Successful)
        ->and($tx)->toHaveCount(1)
        ->and($payment->wallet_transaction_id)->toBe($tx->first()->id)
        ->and($tx->first()->type)->toBe(TransactionType::Funding)
        ->and($tx->first()->status)->toBe(TransactionStatus::Successful)
        ->and($tx->first()->amount_kobo)->toBe($payment->amount_kobo)
        ->and(WalletLedgerEntry::where('transaction_id', $tx->first()->id)->count())->toBe(1)
        ->and(WalletLedgerEntry::where('transaction_id', $tx->first()->id)->first()->entry_type)->toBe(LedgerEntryType::Funding)
        ->and(Wallet::find($payment->wallet_id)->balance_kobo)->toBe($expectedBalance)
        ->and(Artisan::call('wallet:verify'))->toBe(0);
}

function payAssertNotCredited(Payment $payment): void
{
    expect(Transaction::where('idempotency_key', 'payment:'.$payment->reference)->exists())->toBeFalse()
        ->and($payment->fresh()->wallet_transaction_id)->toBeNull()
        ->and(Wallet::find($payment->wallet_id)->balance_kobo)->toBe(0)
        ->and(WalletLedgerEntry::where('wallet_id', $payment->wallet_id)->count())->toBe(0);
}

describe('creating payments', function () {
    it('creates a pending PAY payment with expiry, history and no money movement', function () {
        $user = payCustomer();
        $payment = payService()->create($user, 150_000, payGateway(), (string) Str::uuid());

        expect($payment->reference)->toMatch('/^PAY-[0-9A-Z]{26}$/')
            ->and($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->currency)->toBe('NGN')
            ->and($payment->mode)->toBe(GatewayMode::Sandbox)
            ->and($payment->expires_at->diffInMinutes(now()->addMinutes(60), true))->toBeLessThan(1)
            ->and($payment->statusChanges()->count())->toBe(1)
            ->and(Transaction::count())->toBe(0)
            ->and(Wallet::where('user_id', $user->id)->first()->balance_kobo)->toBe(0);
    });

    it('enforces the funding limits from the Settings Store', function () {
        $gateway = payGateway();
        $user = payCustomer();

        expect(fn () => payService()->create($user, 9_999, $gateway, (string) Str::uuid()))->toThrow(PaymentException::class, '₦100.00')
            ->and(fn () => payService()->create($user, 50_000_001, $gateway, (string) Str::uuid()))->toThrow(PaymentException::class, '₦500,000.00');

        paySetting('payments.min_funding_kobo', 50_000);
        expect(fn () => payService()->create($user, 49_999, $gateway, (string) Str::uuid()))->toThrow(PaymentException::class);
        expect(payService()->create($user, 50_000, $gateway, (string) Str::uuid())->amount_kobo)->toBe(50_000);
    });

    it('never allows more than the pricing maximum amount', function () {
        paySetting('payments.max_funding_kobo', 900_000_000);
        paySetting('pricing.max_amount_kobo', 200_000);

        expect(fn () => payService()->create(payCustomer(), 200_001, payGateway(), (string) Str::uuid()))->toThrow(PaymentException::class, '₦2,000.00');
    });

    it('replays a repeated idempotency key and refuses its reuse for another amount', function () {
        $user = payCustomer();
        $gateway = payGateway();
        $key = (string) Str::uuid();

        $first = payService()->create($user, 100_000, $gateway, $key);
        expect(payService()->create($user, 100_000, $gateway, $key)->id)->toBe($first->id)
            ->and(fn () => payService()->create($user, 200_000, $gateway, $key))->toThrow(PaymentException::class)
            ->and(Payment::count())->toBe(1);
    });

    it('refuses gateways that are not usable for funding', function (array $attributes, array $modes) {
        $gateway = payGateway($attributes, $modes);

        expect(fn () => payService()->create(payCustomer(), 100_000, $gateway, (string) Str::uuid()))->toThrow(PaymentException::class);
    })->with([
        'inactive' => [['status' => GatewayStatus::Inactive], [GatewayMode::Sandbox]],
        'maintenance' => [['status' => GatewayStatus::Maintenance], [GatewayMode::Sandbox]],
        'funding off' => [['wallet_funding' => false], [GatewayMode::Sandbox]],
        'no credentials' => [[], []],
        'unknown driver' => [['driver' => 'missing'], [GatewayMode::Sandbox]],
        'live while live payments are off' => [['mode' => GatewayMode::Live], [GatewayMode::Live]],
    ]);
});

describe('initialization', function () {
    it('stores the gateway reference and an allowed https checkout URL', function () {
        $payment = payStarted();

        expect($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->gateway_reference)->toBe('FGW-'.$payment->reference)
            ->and($payment->checkout_url)->toStartWith('https://'.FakeGateway::CHECKOUT_HOST.'/');
    });

    it('fails the payment safely when the gateway refuses to start checkout', function () {
        FakeGateway::$failInitialize = true;
        $payment = payStarted();

        expect($payment->status)->toBe(PaymentStatus::Failed)
            ->and($payment->failure_reason)->toContain('Checkout could not be started')
            ->and($payment->checkout_url)->toBeNull();
        payAssertNotCredited($payment);
    });

    it('refuses checkout URLs that are not https on an adapter-declared host', function (string $url) {
        FakeGateway::$checkoutUrl = $url;
        $payment = payStarted();

        expect($payment->status)->toBe(PaymentStatus::Failed)->and($payment->checkout_url)->toBeNull();
    })->with([
        'http' => 'http://checkout.fake-gateway.test/pay/x',
        'other host' => 'https://evil.example/pay/x',
        'lookalike host' => 'https://checkout.fake-gateway.test.evil.example/pay',
        'userinfo trick' => 'https://checkout.fake-gateway.test@evil.example/pay',
        'custom port' => 'https://checkout.fake-gateway.test:8443/pay',
        'javascript' => 'javascript:alert(1)',
    ]);

    it('starts checkout only once per payment', function () {
        $payment = payStarted();
        payService()->initialize($payment, 'https://nadabo.test/return');

        expect(FakeGateway::calls('initialize'))->toBe(1);
    });
});

describe('verification and crediting', function () {
    it('credits exactly once when the gateway confirms the exact amount', function () {
        $payment = payStarted(amountKobo: 250_000);
        FakeGateway::pay($payment->reference);

        payService()->verifyAndFinalize($payment, PaymentSource::Return);
        payService()->verifyAndFinalize($payment, PaymentSource::Webhook);
        payService()->verifyAndFinalize($payment, PaymentSource::Reconcile);
        payService()->verifyAndFinalize($payment, PaymentSource::Admin);

        payAssertCreditedOnce($payment, 250_000);
        expect($payment->fresh()->verified_amount_kobo)->toBe(250_000)
            ->and($payment->fresh()->completed_at)->not->toBeNull()
            ->and(Transaction::where('idempotency_key', 'payment:'.$payment->reference)->first()->metadata)->toBe(['payment' => $payment->reference])
            ->and(PaymentStatusChange::where('payment_id', $payment->id)->pluck('new_status')->map->value->all())->toBe(['pending', 'successful']);
    });

    it('leaves a pending payment pending while the gateway has no result', function () {
        $payment = payStarted();

        expect(payService()->verifyAndFinalize($payment, PaymentSource::Return)->status)->toBe(PaymentStatus::Pending);
        payAssertNotCredited($payment);
    });

    it('fails the payment when the gateway reports it failed', function () {
        $payment = payStarted();
        FakeGateway::decline($payment->reference);

        expect(payService()->verifyAndFinalize($payment, PaymentSource::Webhook)->status)->toBe(PaymentStatus::Failed);
        payAssertNotCredited($payment);
    });

    it('moves an amount or currency mismatch to review without crediting', function (?int $amount, string $currency) {
        $payment = payStarted(amountKobo: 250_000);
        FakeGateway::pay($payment->reference, $amount, $currency);

        $after = payService()->verifyAndFinalize($payment, PaymentSource::Webhook);
        expect($after->status)->toBe(PaymentStatus::Review)->and($after->failure_reason)->toContain('mismatch');
        payAssertNotCredited($payment);
    })->with([
        'less' => [249_999, 'NGN'],
        'more' => [250_001, 'NGN'],
        'zero' => [0, 'NGN'],
        'wrong currency' => [250_000, 'USD'],
    ]);

    it('throws and changes nothing when the gateway cannot be reached', function () {
        $payment = payStarted();
        FakeGateway::pay($payment->reference);
        FakeGateway::$failVerify = true;

        expect(fn () => payService()->verifyAndFinalize($payment, PaymentSource::Return))->toThrow(GatewayException::class);
        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
        payAssertNotCredited($payment);
    });

    it('moves a verified payment to review when the credit is refused, with no partial financial state', function () {
        $payment = payStarted(amountKobo: 250_000);
        Wallet::whereKey($payment->wallet_id)->update(['balance_kobo' => WalletService::MAX_BALANCE_KOBO - 100]);
        FakeGateway::pay($payment->reference);

        $after = payService()->verifyAndFinalize($payment, PaymentSource::Webhook);

        expect($after->status)->toBe(PaymentStatus::Review)
            ->and($after->failure_reason)->toContain('wallet credit was refused')
            ->and($after->wallet_transaction_id)->toBeNull()
            ->and(Transaction::count())->toBe(0)
            ->and(WalletLedgerEntry::count())->toBe(0)
            ->and(Wallet::find($payment->wallet_id)->balance_kobo)->toBe(WalletService::MAX_BALANCE_KOBO - 100);
    });

    it('lets only staff settle a payment in review, and only with a verified exact amount', function () {
        $payment = payStarted(amountKobo: 250_000);
        FakeGateway::pay($payment->reference, 1);
        payService()->verifyAndFinalize($payment, PaymentSource::Webhook);
        FakeGateway::pay($payment->reference, 250_000); // the gateway corrects its record

        foreach ([PaymentSource::Webhook, PaymentSource::Return, PaymentSource::Reconcile] as $source) {
            expect(payService()->verifyAndFinalize($payment, $source)->status)->toBe(PaymentStatus::Review);
        }
        payAssertNotCredited($payment);

        payService()->verifyAndFinalize($payment, PaymentSource::Admin, payStaff());
        payAssertCreditedOnce($payment, 250_000);
    });

    it('sends a payment confirmed after it failed to review, never straight to a credit', function () {
        $payment = payStarted();
        FakeGateway::decline($payment->reference);
        payService()->verifyAndFinalize($payment, PaymentSource::Webhook);
        FakeGateway::pay($payment->reference);

        expect(payService()->verifyAndFinalize($payment, PaymentSource::Return)->status)->toBe(PaymentStatus::Failed)
            ->and(payService()->verifyAndFinalize($payment, PaymentSource::Webhook)->status)->toBe(PaymentStatus::Review);
        payAssertNotCredited($payment);
    });

    it('verifies with the payment\'s own mode and never falls back to another mode', function () {
        $gateway = payGateway();
        $payment = payStarted(gateway: $gateway);
        FakeGateway::pay($payment->reference);
        $gateway->forceFill(['mode' => GatewayMode::Live])->save(); // switched after the payment started

        expect(app(GatewayRegistry::class)->contextFor($gateway->fresh(), GatewayMode::Sandbox)->mode)->toBe(GatewayMode::Sandbox)
            ->and(fn () => app(GatewayRegistry::class)->contextFor($gateway->fresh()))->toThrow(GatewayNotConfigured::class);

        payService()->verifyAndFinalize($payment, PaymentSource::Return);
        payAssertCreditedOnce($payment, 250_000);
    });

    it('never credits from the payment model or by changing its status directly', function () {
        $payment = payStarted();

        expect(fn () => $payment->forceFill(['amount_kobo' => 1])->save())->toThrow(LogicException::class)
            ->and(fn () => $payment->fresh()->forceFill(['reference' => 'PAY-X'])->save())->toThrow(LogicException::class)
            ->and(fn () => $payment->fresh()->delete())->toThrow(LogicException::class);

        $payment->fresh()->forceFill(['status' => PaymentStatus::Failed])->save();
        expect(fn () => $payment->fresh()->forceFill(['status' => PaymentStatus::Successful])->save())->toThrow(PaymentException::class);
        payAssertNotCredited($payment);
    });

    it('links a payment to at most one wallet transaction', function () {
        $payment = payStarted();
        FakeGateway::pay($payment->reference);
        payService()->verifyAndFinalize($payment, PaymentSource::Return);
        $other = app(WalletService::class)->credit(Wallet::find($payment->wallet_id), 1, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'x');

        expect(fn () => $payment->fresh()->forceFill(['wallet_transaction_id' => $other->transaction->id])->save())->toThrow(LogicException::class);
    });

    it('keeps history append-only', function () {
        $change = payStarted()->statusChanges()->first();

        expect(fn () => $change->forceFill(['note' => 'x'])->save())->toThrow(LogicException::class)
            ->and(fn () => $change->delete())->toThrow(LogicException::class);
    });
});

describe('reconciliation', function () {
    it('settles paid and failed payments, leaves young and unpaid ones pending', function () {
        $paid = payStarted();
        $declined = payStarted();
        $waiting = payStarted();
        $this->travel(3)->minutes();
        $young = payStarted();
        FakeGateway::pay($paid->reference);
        FakeGateway::decline($declined->reference);
        FakeGateway::pay($young->reference);

        Artisan::call('payments:reconcile');

        expect($paid->fresh()->status)->toBe(PaymentStatus::Successful)
            ->and($declined->fresh()->status)->toBe(PaymentStatus::Failed)
            ->and($waiting->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and($young->fresh()->status)->toBe(PaymentStatus::Pending)
            ->and(PaymentStatusChange::where('payment_id', $paid->id)->latest('id')->first()->source)->toBe(PaymentSource::Reconcile);
        payAssertCreditedOnce($paid, 250_000);
    });

    it('fails an unpaid payment only after expiry plus the grace period and a gateway check', function () {
        $payment = payStarted();

        $this->travel(60 + 29)->minutes();
        Artisan::call('payments:reconcile');
        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

        $this->travel(2)->minutes();
        FakeGateway::$failVerify = true;
        Artisan::call('payments:reconcile');
        expect($payment->fresh()->status)->toBe(PaymentStatus::Pending); // could not ask the gateway: nothing changes

        FakeGateway::$failVerify = false;
        Artisan::call('payments:reconcile');
        expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
            ->and($payment->fresh()->failure_reason)->toBe('Not paid before the payment expired.');
    });

    it('still credits a payment the gateway confirms after expiry while it is pending', function () {
        $payment = payStarted();
        $this->travel(5)->hours();
        FakeGateway::pay($payment->reference);

        Artisan::call('payments:reconcile');

        payAssertCreditedOnce($payment, 250_000);
    });

    it('is scheduled every five minutes without overlapping, with a daily payload prune', function () {
        $events = collect(app(Schedule::class)->events());
        $reconcile = $events->first(fn ($e) => str_contains($e->command, 'payments:reconcile'));
        $prune = $events->first(fn ($e) => str_contains($e->command, 'payments:prune-webhooks'));

        expect($reconcile->expression)->toBe('*/5 * * * *')->and($reconcile->withoutOverlapping)->toBeTrue()
            ->and($prune->expression)->toBe('0 0 * * *');
    });
});
