<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanProviderRoute;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\User;
use App\Services\Purchases\PurchaseService;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserType;
use App\Support\Providers\CredentialKey;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

/*
 * Shared helpers for the Phase 10 purchase engine tests (feature and MariaDB
 * concurrency). Only the test-only FakeProvider is ever registered; the
 * credential value is an obviously fake test string.
 */

const PUX_KEY = 'fake-provider-key-NOT-REAL-cp3';

function puxDrivers(): void
{
    config(['providers.drivers' => ['fake-provider' => FakeProvider::class]]);
    FakeProvider::reset();
}

/** An available plan in service $slug, priced for Subscribers (fixed price, or variable with discount/fee). */
function puxPlan(string $slug = 'data', int $priceKobo = 50_000, bool $variable = false, array $planAttributes = []): Plan
{
    $service = Service::where('slug', $slug)->first() ?? Service::factory()->create(['name' => Str::headline($slug), 'slug' => $slug]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'MTN', 'code' => $slug.'-mtn-'.Str::lower(Str::random(6)), 'network' => 'mtn']);
    $plan = Plan::factory()->create($planAttributes + [
        'product_id' => $product->id, 'name' => $variable ? 'Airtime' : '1GB', 'code' => $product->code.'-p',
        'amount_type' => $variable ? 'variable' : 'fixed',
        'min_amount_kobo' => $variable ? 5_000 : null, 'max_amount_kobo' => $variable ? 5_000_000 : null,
    ]);
    PlanPrice::factory()->create($variable
        ? ['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => null, 'discount_bps' => 200, 'fee_kobo' => 0]
        : ['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => $priceKobo]);

    return $plan->fresh();
}

/** An executable route on $plan through a FakeProvider-driven, configured, active provider. */
function puxRoute(Plan $plan, int $priority = 1, array $cost = ['cost_type' => 'fixed', 'cost_kobo' => 45_000], ?string $driver = 'fake-provider'): PlanProviderRoute
{
    $provider = Provider::factory()->create(['name' => 'Provider '.Str::random(5), 'code' => 'prov-'.Str::lower(Str::random(8)), 'status' => 'active',
        'driver' => $driver, 'settings' => ['required_credentials' => [CredentialKey::ApiKey->value]]]);
    (new ProviderCredential)->forceFill(['provider_id' => $provider->id, 'key' => CredentialKey::ApiKey, 'value' => PUX_KEY, 'hint' => 'cp3x'])->save();
    (new ProviderService)->forceFill(['provider_id' => $provider->id, 'service_id' => $plan->product->service_id, 'requires_plan_code' => true, 'is_active' => true])->save();

    return tap((new PlanProviderRoute)->forceFill($cost + ['plan_id' => $plan->id, 'provider_id' => $provider->id, 'priority' => $priority,
        'provider_plan_code' => 'CODE'.$priority, 'is_active' => true]))->save();
}

/** A Subscriber whose wallet holds $balanceKobo (funded by a test adjustment through WalletService). */
function puxCustomer(int $balanceKobo = 200_000): User
{
    $user = User::factory()->create(['user_type' => UserType::Subscriber]);
    $wallet = app(WalletService::class)->walletFor($user);
    if ($balanceKobo > 0) {
        app(WalletService::class)->credit($wallet, $balanceKobo, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Test funding');
    }

    return $user;
}

function puxService(): PurchaseService
{
    return app(PurchaseService::class);
}
