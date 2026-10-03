<?php

use App\Actions\Admin\Pricing\SavePlanPrices;
use App\Actions\Admin\Pricing\SetPlanPriceStatus;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanPriceChange;
use App\Models\Product;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Pricing\PriceResolver;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserType;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\KoboAmount;
use App\Support\Pricing\PricingLimits;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
});

function prStaff(SystemRole|string $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

/** Custom role holding exactly the given permissions (plus admin.access). */
function prRole(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'PR '.implode(' ', $permissions), 'guard_name' => 'admin'])
        ->givePermissionTo(['admin.access', ...$permissions]);

    return prStaff($role->name);
}

/** Active fixed plan in an active category → service → product chain. */
function prFixedPlan(string $serviceSlug = 'data'): Plan
{
    $service = Service::factory()->create(['name' => ucfirst($serviceSlug), 'slug' => $serviceSlug]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'MTN', 'code' => "{$serviceSlug}-mtn", 'network' => 'mtn']);

    return Plan::factory()->create(['product_id' => $product->id, 'name' => '1GB', 'code' => "{$serviceSlug}-mtn-1gb"]);
}

/** Active variable plan with face-value limits ₦50 – ₦50,000. */
function prVariablePlan(array $attributes = []): Plan
{
    $service = Service::factory()->create(['name' => 'Airtime', 'slug' => 'airtime']);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'MTN', 'code' => 'airtime-mtn', 'network' => 'mtn']);

    return Plan::factory()->create($attributes + ['product_id' => $product->id, 'name' => 'VTU', 'code' => 'airtime-mtn-vtu',
        'amount_type' => 'variable', 'min_amount_kobo' => 5_000, 'max_amount_kobo' => 5_000_000]);
}

function prPrice(Plan $plan, UserType $type, array $attributes = []): PlanPrice
{
    return PlanPrice::factory()->create($attributes + ['plan_id' => $plan->id, 'user_type' => $type]);
}

/** Valid edit-prices form submission. */
function prForm(Plan $plan, array $prices): array
{
    return ['prices' => $prices, 'confirm' => '1', 'fingerprint' => SavePlanPrices::fingerprint($plan)];
}

function resolver(): PriceResolver
{
    return app(PriceResolver::class);
}

describe('schema', function () {
    it('creates plan_prices and plan_price_changes with kobo columns', function () {
        expect(Schema::hasColumns('plan_prices', ['id', 'plan_id', 'user_type', 'price_kobo', 'discount_bps', 'fee_kobo', 'is_active', 'updated_by', 'created_at', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumns('plan_price_changes', ['id', 'plan_price_id', 'plan_id', 'user_type', 'old_price_kobo', 'new_price_kobo', 'old_discount_bps', 'new_discount_bps',
                'old_fee_kobo', 'new_fee_kobo', 'old_is_active', 'new_is_active', 'changed_by', 'created_at']))->toBeTrue()
            ->and(Schema::hasColumn('plan_price_changes', 'updated_at'))->toBeFalse();
    });

    it('adds only face-value limits to plans and no price or provider columns to the catalog', function () {
        expect(Schema::hasColumns('plans', ['min_amount_kobo', 'max_amount_kobo']))->toBeTrue();

        foreach (['services', 'products', 'plans'] as $table) {
            foreach (['price', 'price_kobo', 'cost', 'cost_kobo', 'discount_bps', 'fee_kobo', 'provider_id', 'provider_price', 'commission', 'markup'] as $column) {
                expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} exists");
            }
        }
        foreach (['cost_kobo', 'provider_id', 'provider_price', 'commission', 'markup', 'cashback'] as $column) {
            expect(Schema::hasColumn('plan_prices', $column))->toBeFalse("plan_prices.{$column} exists");
        }
    });

    it('allows only one price per plan and customer type', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Vendor);

        expect(fn () => prPrice($plan, UserType::Vendor))->toThrow(QueryException::class)
            ->and(PlanPrice::count())->toBe(1);
        prPrice($plan, UserType::Affiliate);
        expect(PlanPrice::count())->toBe(2);
    });

    it('refuses to delete a plan that has prices at the database level', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber);

        expect(fn () => $plan->delete())->toThrow(QueryException::class);
    });
});

describe('money input', function () {
    it('converts naira text to integer kobo without floats', function (string $input, int $kobo) {
        expect(KoboAmount::parse($input))->toBe($kobo)->toBeInt();
    })->with([
        ['1250', 125_000], ['1,250.50', 125_050], ['0.1', 10], ['0.01', 1], ['1.05', 105], ['1,000,000', 100_000_000], ['0', 0], [' 99.99 ', 9_999],
    ]);

    it('rejects invalid money input', function (mixed $input) {
        expect(KoboAmount::parse($input))->toBeNull();
    })->with(['abc', '-5', '1.005', '1e3', '1,25', '1,2345', '₦100', '', '10.', '.5', '9999999999999999', 1.5, null]);

    it('converts percentages to basis points and back', function () {
        expect(BasisPoints::parse('2.5'))->toBe(250)->and(BasisPoints::parse('0'))->toBe(0)->and(BasisPoints::parse('99.99'))->toBe(9_999)
            ->and(BasisPoints::parse('100'))->toBeNull()->and(BasisPoints::parse('2.555'))->toBeNull()->and(BasisPoints::parse('-1'))->toBeNull()
            ->and(BasisPoints::label(250))->toBe('2.5%')->and(BasisPoints::label(105))->toBe('1.05%')
            ->and(KoboAmount::toInput(125_005))->toBe('1250.05');
    });
});

describe('system maximum setting', function () {
    it('seeds a configurable safety limit and reads it from the Settings Store', function () {
        expect(PricingLimits::maxAmountKobo())->toBe(1_000_000_000);

        app(SettingsStore::class)->set('pricing.max_amount_kobo', 500_000);

        expect(PricingLimits::maxAmountKobo())->toBe(500_000);
    });

    it('lets authorized staff change the limit on the Settings page', function () {
        $this->actingAs(prStaff(), 'admin')->get('/admin/settings')->assertSee('Maximum amount (kobo)')->assertSee('data-settings-group="pricing"', false);
    });

    it('applies the configured limit to price validation', function () {
        $plan = prFixedPlan();
        app(SettingsStore::class)->set('pricing.max_amount_kobo', 100_000); // ₦1,000
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['subscriber' => ['price' => '1,000.01']]))
            ->assertSessionHasErrors(['prices.subscriber.price' => 'The amount may not be more than ₦1,000.00 (system maximum).']);
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['subscriber' => ['price' => '1,000']]))->assertSessionHasNoErrors();

        expect($plan->prices()->first()->price_kobo)->toBe(100_000);
    });
});

describe('price resolution', function () {
    it('gives each of the four customer types its own fixed price', function () {
        $plan = prFixedPlan();
        $amounts = ['subscriber' => 30_000, 'vendor' => 28_000, 'affiliate' => 29_000, 'api_user' => 27_500];
        foreach ($amounts as $type => $kobo) {
            prPrice($plan, UserType::from($type), ['price_kobo' => $kobo]);
        }

        foreach ($amounts as $type => $kobo) {
            $quote = resolver()->quote($plan->fresh(), UserType::from($type));
            expect($quote->available)->toBeTrue()->and($quote->amountKobo)->toBe($kobo)->toBeInt()->and($quote->userType)->toBe(UserType::from($type));
        }
    });

    it('never falls back to another customer type', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 30_000]);

        foreach ([UserType::Vendor, UserType::Affiliate, UserType::ApiUser] as $type) {
            $quote = resolver()->quote($plan->fresh(), $type);
            expect($quote->available)->toBeFalse()->and($quote->amountKobo)->toBeNull()->and($quote->reason)->toBe("No price for {$type->label()}.");
        }
    });

    it('treats a disabled price as unavailable without falling back', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 30_000]);
        prPrice($plan, UserType::Vendor, ['price_kobo' => 28_000, 'is_active' => false]);

        $quote = resolver()->quote($plan->fresh(), UserType::Vendor);

        expect($quote->available)->toBeFalse()->and($quote->reason)->toBe('Price disabled for Vendor.');
    });

    it('checks the full category → service → product → plan chain', function (string $level, string $reason) {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 30_000]);
        $target = match ($level) {
            'category' => $plan->product->service->category,
            'service' => $plan->product->service,
            'product' => $plan->product,
            'plan' => $plan,
        };
        $target->forceFill(['is_active' => false])->save();

        $quote = resolver()->quote(Plan::find($plan->id), UserType::Subscriber);

        expect($quote->available)->toBeFalse()->and($quote->reason)->toBe("Plan unavailable: {$reason}.");
    })->with([
        ['category', 'Active (category disabled)'],
        ['service', 'Active (service disabled)'],
        ['product', 'Active (product disabled)'],
        ['plan', 'Disabled'],
    ]);

    it('applies discount and fee to the face value of variable plans', function () {
        $plan = prVariablePlan();
        prPrice($plan, UserType::Vendor, ['price_kobo' => null, 'discount_bps' => 250, 'fee_kobo' => 0]);
        prPrice($plan, UserType::Subscriber, ['price_kobo' => null, 'discount_bps' => 0, 'fee_kobo' => 5_000]);

        $vendor = resolver()->quote($plan->fresh(), UserType::Vendor, 100_000);
        $subscriber = resolver()->quote($plan->fresh(), UserType::Subscriber, 100_000);

        expect($vendor->amountKobo)->toBe(97_500)->and($vendor->discountKobo)->toBe(2_500)->and($vendor->feeKobo)->toBe(0)->and($vendor->faceValueKobo)->toBe(100_000)
            ->and($subscriber->amountKobo)->toBe(105_000)->and($subscriber->discountKobo)->toBe(0)->and($subscriber->feeKobo)->toBe(5_000);
    });

    it('rounds the discount down using integer arithmetic only', function () {
        $plan = prVariablePlan();
        prPrice($plan, UserType::Affiliate, ['price_kobo' => null, 'discount_bps' => 333, 'fee_kobo' => 7]);

        // 12,345 kobo × 3.33% = 411.0885 kobo → discount 411; 12,345 − 411 + 7 = 11,941.
        $quote = resolver()->quote($plan->fresh(), UserType::Affiliate, 12_345);

        expect($quote->discountKobo)->toBe(411)->toBeInt()->and($quote->amountKobo)->toBe(11_941)->toBeInt();
    });

    it('validates the face value against the plan limits', function () {
        $plan = prVariablePlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => null, 'discount_bps' => 0, 'fee_kobo' => 0]);
        $p = fn () => Plan::find($plan->id);

        expect(resolver()->quote($p(), UserType::Subscriber)->reason)->toBe('Enter an amount.')
            ->and(resolver()->quote($p(), UserType::Subscriber, 4_999)->reason)->toBe('Amount must be between ₦50.00 – ₦50,000.00.')
            ->and(resolver()->quote($p(), UserType::Subscriber, 5_000_001)->available)->toBeFalse()
            ->and(resolver()->quote($p(), UserType::Subscriber, 5_000)->amountKobo)->toBe(5_000)
            ->and(resolver()->quote($p(), UserType::Subscriber, 5_000_000)->amountKobo)->toBe(5_000_000);

        $plan->forceFill(['min_amount_kobo' => null, 'max_amount_kobo' => null])->save();
        expect(resolver()->quote($p(), UserType::Subscriber, 10_000)->reason)->toBe('Amount limits are not set for this plan.');
    });

    it('refuses prices above a lowered system maximum', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 200_000]);
        app(SettingsStore::class)->set('pricing.max_amount_kobo', 100_000);

        expect(resolver()->quote($plan->fresh(), UserType::Subscriber)->reason)->toBe('Price is above the system maximum amount.');
    });

    it('ignores any face value for fixed plans', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 30_000]);

        expect(resolver()->quote($plan->fresh(), UserType::Subscriber, 1)->amountKobo)->toBe(30_000);
    });

    it('resolves a signed-in customer by their own type, including API users', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 30_000]);
        prPrice($plan, UserType::ApiUser, ['price_kobo' => 27_000]);

        $api = User::factory()->create(['user_type' => UserType::ApiUser]);
        $subscriber = User::factory()->create(['user_type' => UserType::Subscriber]);
        $vendor = User::factory()->create(['user_type' => UserType::Vendor]);

        expect(resolver()->quoteFor($plan->fresh(), $api)->amountKobo)->toBe(27_000)
            ->and(resolver()->quoteFor($plan->fresh(), $subscriber)->amountKobo)->toBe(30_000)
            ->and(resolver()->quoteFor($plan->fresh(), $vendor)->reason)->toBe('No price for Vendor.');

        $api->forceFill(['status' => 'disabled'])->save();
        expect(resolver()->quoteFor($plan->fresh(), $api->fresh())->reason)->toBe('Customer account is disabled.');
    });

    it('has no service-specific logic', function () {
        $data = prFixedPlan('data');
        $cable = prFixedPlan('cable-tv');
        prPrice($data, UserType::Vendor, ['price_kobo' => 12_345]);
        prPrice($cable, UserType::Vendor, ['price_kobo' => 12_345]);

        expect(resolver()->quote($data->fresh(), UserType::Vendor))->toEqual(resolver()->quote($cable->fresh(), UserType::Vendor));

        // Code only (comments excluded): no service, network or slug-specific branches.
        $source = collect(token_get_all(file_get_contents(app_path('Services/Pricing/PriceResolver.php'))))
            ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
            ->map(fn ($t) => is_array($t) ? $t[1] : $t)->implode('');
        foreach (['airtime', 'electricity', 'smile', 'mtn', 'slug', 'network', 'service->'] as $word) {
            expect(str_contains(strtolower($source), $word))->toBeFalse("resolver mentions {$word}");
        }
    });
});

describe('admin pricing pages', function () {
    it('shows every customer type, missing prices and an empty history', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 30_000]);
        $this->actingAs(prStaff(), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/prices")->assertOk()
            ->assertSee('data-price="subscriber"', false)->assertSee('data-price="vendor"', false)
            ->assertSee('data-price="affiliate"', false)->assertSee('data-price="api_user"', false)
            ->assertSee('₦300.00')->assertSee('Missing')->assertSee('Priced 1/4')
            ->assertSee('No price changes yet.')->assertSee('Edit prices');
    });

    it('saves fixed prices for all four types in kobo and records history', function () {
        $plan = prFixedPlan();
        $staff = prStaff();
        $this->actingAs($staff, 'admin');

        $this->get("/admin/services/plans/{$plan->id}/prices/edit")->assertOk()->assertSee('Copy Subscriber values to all types')->assertSee('id="price-api_user"', false);
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, [
            'subscriber' => ['price' => '300'], 'vendor' => ['price' => '280.50'], 'affiliate' => ['price' => '290'], 'api_user' => ['price' => '1,275.05'],
        ]))->assertRedirect(route('admin.services.plans.prices', $plan))->assertSessionHas('status', 'Prices saved (4 changes).');

        $prices = $plan->prices()->get()->keyBy(fn ($p) => $p->user_type->value);
        expect($prices['subscriber']->price_kobo)->toBe(30_000)->and($prices['vendor']->price_kobo)->toBe(28_050)
            ->and($prices['affiliate']->price_kobo)->toBe(29_000)->and($prices['api_user']->price_kobo)->toBe(127_505)
            ->and($prices->every(fn ($p) => $p->is_active && $p->updated_by === $staff->id && $p->discount_bps === null && $p->fee_kobo === null))->toBeTrue()
            ->and(PlanPriceChange::count())->toBe(4);

        $change = PlanPriceChange::firstWhere('user_type', 'vendor');
        expect($change->old_price_kobo)->toBeNull()->and($change->old_is_active)->toBeNull()->and($change->new_price_kobo)->toBe(28_050)
            ->and($change->new_is_active)->toBeTrue()->and($change->changed_by)->toBe($staff->id)->and($change->created_at)->not->toBeNull();
        $this->get("/admin/services/plans/{$plan->id}/prices")->assertSee('Priced 4/4')->assertSee('Not priced → ₦280.50')->assertSee($staff->name);
    });

    it('saves discount and fee for variable plans', function () {
        $plan = prVariablePlan();
        $this->actingAs(prStaff(), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/prices/edit")->assertOk()->assertSee('id="discount-vendor"', false)->assertSee('id="fee-vendor"', false)->assertDontSee('id="price-vendor"', false);
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, [
            'subscriber' => ['discount' => '0', 'fee' => '0'], 'vendor' => ['discount' => '2.5', 'fee' => '10'],
        ]))->assertSessionHasNoErrors();

        $vendor = $plan->prices()->where('user_type', 'vendor')->first();
        expect($vendor->discount_bps)->toBe(250)->and($vendor->fee_kobo)->toBe(1_000)->and($vendor->price_kobo)->toBeNull()
            ->and($plan->prices()->count())->toBe(2);
        $this->get("/admin/services/plans/{$plan->id}/prices")->assertSee('2.5% off + ₦10.00 fee');
    });

    it('leaves blank customer types unpriced', function () {
        $plan = prFixedPlan();
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['subscriber' => ['price' => '300'], 'vendor' => ['price' => '']]))->assertSessionHasNoErrors();

        expect($plan->prices()->pluck('user_type')->map->value->all())->toBe(['subscriber']);
    });

    it('records only real changes with old and new values', function () {
        $plan = prFixedPlan();
        $this->actingAs(prStaff(), 'admin');
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['subscriber' => ['price' => '300'], 'vendor' => ['price' => '280']]));

        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['subscriber' => ['price' => '300.00'], 'vendor' => ['price' => '275']]))
            ->assertSessionHas('status', 'Prices saved (1 change).');
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['subscriber' => ['price' => '300'], 'vendor' => ['price' => '275']]))
            ->assertSessionHas('status', 'No price changes to save.');

        $last = PlanPriceChange::latest('id')->first();
        expect(PlanPriceChange::count())->toBe(3)
            ->and($last->user_type)->toBe(UserType::Vendor)->and($last->old_price_kobo)->toBe(28_000)->and($last->new_price_kobo)->toBe(27_500)
            ->and($last->old_is_active)->toBeTrue()->and($last->new_is_active)->toBeTrue();
    });

    it('does not let an existing price be blanked', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Vendor, ['price_kobo' => 28_000]);
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '']]))
            ->assertSessionHasErrors(['prices.vendor.price' => 'Vendor already has a price. Enter a value, or disable it instead.']);

        expect($plan->prices()->first()->price_kobo)->toBe(28_000);
    });

    it('rejects invalid fixed prices', function (mixed $value, string $message) {
        $plan = prFixedPlan();
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['subscriber' => ['price' => $value]]))
            ->assertSessionHasErrors(['prices.subscriber.price' => $message]);
        expect(PlanPrice::count())->toBe(0)->and(PlanPriceChange::count())->toBe(0);
    })->with([
        ['abc', 'Enter an amount in naira, e.g. 1,250.50.'],
        ['-5', 'Enter an amount in naira, e.g. 1,250.50.'],
        ['1.005', 'Enter an amount in naira, e.g. 1,250.50.'],
        ['1e3', 'Enter an amount in naira, e.g. 1,250.50.'],
        ['0', 'The amount must be at least ₦0.01.'],
        ['0.00', 'The amount must be at least ₦0.01.'],
        ['10,000,000.01', 'The amount may not be more than ₦10,000,000.00 (system maximum).'],
    ]);

    it('prohibits the wrong price fields for the plan amount type', function () {
        $fixed = prFixedPlan();
        $variable = prVariablePlan();
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$fixed->id}/prices", prForm($fixed, ['vendor' => ['price' => '100', 'discount' => '2', 'fee' => '1']]))
            ->assertSessionHasErrors(['prices.vendor.discount' => 'Fixed plans use a fixed price, not a discount.', 'prices.vendor.fee' => 'Fixed plans use a fixed price, not a fee.']);
        $this->put("/admin/services/plans/{$variable->id}/prices", prForm($variable, ['vendor' => ['price' => '100', 'discount' => '2', 'fee' => '0']]))
            ->assertSessionHasErrors(['prices.vendor.price' => 'Variable-amount plans use a discount and fee, not a fixed price.']);

        expect(PlanPrice::count())->toBe(0);
    });

    it('validates variable discounts and fees', function (array $row, string $field) {
        $plan = prVariablePlan();
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => $row]))->assertSessionHasErrors("prices.vendor.$field");
        expect(PlanPrice::count())->toBe(0);
    })->with([
        'discount of 100%' => [['discount' => '100', 'fee' => '0'], 'discount'],
        'discount with 3 decimals' => [['discount' => '2.555', 'fee' => '0'], 'discount'],
        'negative discount' => [['discount' => '-1', 'fee' => '0'], 'discount'],
        'missing fee' => [['discount' => '2'], 'fee'],
        'missing discount' => [['fee' => '10'], 'discount'],
        'negative fee' => [['discount' => '1', 'fee' => '-10'], 'fee'],
        'fee above maximum' => [['discount' => '1', 'fee' => '10,000,001'], 'fee'],
    ]);

    it('requires amount limits before a variable plan is priced', function () {
        $plan = prVariablePlan(['min_amount_kobo' => null, 'max_amount_kobo' => null]);
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['discount' => '1', 'fee' => '0']]))
            ->assertSessionHasErrors(['prices' => 'Set the minimum and maximum amount on the plan before pricing it.']);
        expect(PlanPrice::count())->toBe(0);
    });

    it('requires confirmation and a current form', function () {
        $plan = prFixedPlan();
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}/prices", ['prices' => ['vendor' => ['price' => '100']], 'fingerprint' => SavePlanPrices::fingerprint($plan)])
            ->assertSessionHasErrors(['confirm' => 'Confirm that these prices are correct before saving.']);

        // After a validation error the confirmation is never pre-ticked: staff must confirm again.
        $this->from("/admin/services/plans/{$plan->id}/prices/edit")
            ->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => 'abc']]))->assertRedirect();
        $html = $this->get("/admin/services/plans/{$plan->id}/prices/edit")->assertSee('value="abc"', false)->getContent();
        expect(preg_match('/<input type="checkbox" name="confirm"[^>]*checked/', $html))->toBe(0);

        $stale = SavePlanPrices::fingerprint($plan);
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 30_000]); // someone else saves meanwhile
        $this->put("/admin/services/plans/{$plan->id}/prices", ['prices' => ['subscriber' => ['price' => '300'], 'vendor' => ['price' => '100']], 'confirm' => '1', 'fingerprint' => $stale])
            ->assertSessionHasErrors(['prices' => 'These prices were changed by someone else while you were editing. Reload the page and try again.']);

        expect(PlanPrice::count())->toBe(1);
    });

    it('enables and disables one customer type with history', function () {
        $plan = prFixedPlan();
        $price = prPrice($plan, UserType::Affiliate, ['price_kobo' => 29_000]);
        $this->actingAs(prStaff(), 'admin');

        $this->from("/admin/services/plans/{$plan->id}/prices")->patch("/admin/services/plans/{$plan->id}/prices/affiliate/status", ['is_active' => 0])
            ->assertRedirect("/admin/services/plans/{$plan->id}/prices")->assertSessionHas('status');
        expect($price->fresh()->is_active)->toBeFalse()->and(resolver()->quote($plan->fresh(), UserType::Affiliate)->available)->toBeFalse();

        $this->patch("/admin/services/plans/{$plan->id}/prices/affiliate/status", ['is_active' => 0]); // no-op, no history
        $this->patch("/admin/services/plans/{$plan->id}/prices/affiliate/status", ['is_active' => 1]);

        expect($price->fresh()->is_active)->toBeTrue()->and($price->fresh()->price_kobo)->toBe(29_000)
            ->and(PlanPriceChange::pluck('new_is_active')->all())->toBe([false, true]);
        $this->patch("/admin/services/plans/{$plan->id}/prices/vendor/status", ['is_active' => 0])->assertNotFound();
        $this->patch("/admin/services/plans/{$plan->id}/prices/reseller/status", ['is_active' => 0])->assertNotFound();
    });

    it('warns about types priced above or below Subscriber without blocking or changing prices', function () {
        $plan = prFixedPlan();
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, [
            'subscriber' => ['price' => '300'], 'vendor' => ['price' => '320'], 'api_user' => ['price' => '250'], 'affiliate' => ['price' => '300'],
        ]))->assertSessionHasNoErrors();

        $this->get("/admin/services/plans/{$plan->id}/prices")->assertSee('data-price-warnings', false)
            ->assertSee('Vendor price ₦320.00 is higher than the Subscriber price ₦300.00.')
            ->assertSee('API User price ₦250.00 is lower than the Subscriber price ₦300.00.')
            ->assertDontSee('Affiliate price');
        expect($plan->prices()->where('user_type', 'vendor')->value('price_kobo'))->toBe(32_000);
    });

    it('previews the resolved price for a customer type', function () {
        $fixed = prFixedPlan();
        prPrice($fixed, UserType::Vendor, ['price_kobo' => 28_000]);
        $variable = prVariablePlan();
        prPrice($variable, UserType::Vendor, ['price_kobo' => null, 'discount_bps' => 250, 'fee_kobo' => 0]);
        $this->actingAs(prStaff(), 'admin');

        $this->get("/admin/services/plans/{$fixed->id}/prices?preview_type=vendor")->assertSee('data-quote="available"', false)->assertSee('Vendor pays ₦280.00');
        $this->get("/admin/services/plans/{$fixed->id}/prices?preview_type=api_user")->assertSee('data-quote="unavailable"', false)->assertSee('No price for API User.');
        $this->get("/admin/services/plans/{$variable->id}/prices?preview_type=vendor&preview_amount=1,000")
            ->assertSee('Vendor pays ₦975.00')->assertSee('Amount ₦1,000.00 − discount ₦25.00 + fee ₦0.00');
        $this->get("/admin/services/plans/{$variable->id}/prices?preview_type=vendor&preview_amount=abc")->assertSee('data-quote="error"', false);
        $this->get("/admin/services/plans/{$variable->id}/prices?preview_type=vendor&preview_amount=10")->assertSee('Amount must be between ₦50.00 – ₦50,000.00.');
        $this->get("/admin/services/plans/{$fixed->id}/prices?preview_type=reseller")->assertSessionHasErrors('preview_type');
    });

    it('shows Priced n/4 and filters plans with missing prices', function () {
        $full = prFixedPlan('data');
        foreach (UserType::cases() as $type) {
            prPrice($full, $type, ['price_kobo' => 10_000]);
        }
        $partial = prFixedPlan('cable-tv');
        prPrice($partial, UserType::Subscriber, ['price_kobo' => 10_000]);
        prPrice($partial, UserType::Vendor, ['price_kobo' => 9_000, 'is_active' => false]);
        $none = prVariablePlan();
        $this->actingAs(prStaff(), 'admin');

        $this->get('/admin/services/plans')->assertSee('data-priced="4/4"', false)->assertSee('data-priced="1/4"', false)->assertSee('data-priced="0/4"', false);
        $this->get('/admin/services/plans?pricing=missing')->assertSee("data-plan=\"{$partial->code}\"", false)->assertSee("data-plan=\"{$none->code}\"", false)
            ->assertDontSee("data-plan=\"{$full->code}\"", false);
        $this->get('/admin/services/plans?pricing=complete')->assertSee("data-plan=\"{$full->code}\"", false)->assertDontSee("data-plan=\"{$none->code}\"", false);
        $this->get("/admin/services/plans/{$partial->id}")->assertSee('data-plan-pricing', false)->assertSee('Priced 1/4')->assertSee('Manage prices');
        $this->get('/admin/services/plans?pricing=bogus')->assertSessionHasErrors('pricing');
    });
});

describe('plan face-value limits', function () {
    it('saves limits in kobo for variable plans', function () {
        $plan = prVariablePlan(['min_amount_kobo' => null, 'max_amount_kobo' => null]);
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}", ['product_id' => $plan->product_id, 'name' => 'VTU', 'amount_type' => 'variable', 'min_amount' => '50', 'max_amount' => '50,000'])
            ->assertSessionHasNoErrors();

        expect($plan->fresh()->min_amount_kobo)->toBe(5_000)->and($plan->fresh()->max_amount_kobo)->toBe(5_000_000);
        $this->get("/admin/services/plans/{$plan->id}")->assertSee('₦50.00 – ₦50,000.00');
    });

    it('validates the limits', function (array $input, string $field) {
        $plan = prVariablePlan(['min_amount_kobo' => null, 'max_amount_kobo' => null]);
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}", $input + ['product_id' => $plan->product_id, 'name' => 'VTU', 'amount_type' => 'variable'])->assertSessionHasErrors($field);
        expect($plan->fresh()->min_amount_kobo)->toBeNull();
    })->with([
        'min zero' => [['min_amount' => '0', 'max_amount' => '100'], 'min_amount'],
        'max below min' => [['min_amount' => '100', 'max_amount' => '50'], 'max_amount'],
        'max above system maximum' => [['min_amount' => '1', 'max_amount' => '10,000,000.01'], 'max_amount'],
        'min only' => [['min_amount' => '1'], 'max_amount'],
        'max only' => [['max_amount' => '100'], 'min_amount'],
        'invalid amount' => [['min_amount' => 'ten', 'max_amount' => '100'], 'min_amount'],
        'fixed plan with limits' => [['amount_type' => 'fixed', 'min_amount' => '1', 'max_amount' => '100'], 'min_amount'],
    ]);

    it('clears limits when a plan without prices becomes fixed', function () {
        $plan = prVariablePlan();
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}", ['product_id' => $plan->product_id, 'name' => 'VTU', 'amount_type' => 'fixed'])->assertSessionHasNoErrors();

        expect($plan->fresh()->min_amount_kobo)->toBeNull()->and($plan->fresh()->max_amount_kobo)->toBeNull();
    });

    it('locks the amount type once a plan has prices', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Subscriber, ['price_kobo' => 30_000]);
        $this->actingAs(prStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}", ['product_id' => $plan->product_id, 'name' => '1GB', 'amount_type' => 'variable', 'min_amount' => '1', 'max_amount' => '100'])
            ->assertSessionHasErrors(['amount_type' => 'The amount type cannot be changed because this plan already has prices.']);
        $this->put("/admin/services/plans/{$plan->id}", ['product_id' => $plan->product_id, 'name' => '1GB renamed', 'amount_type' => 'fixed'])->assertSessionHasNoErrors();
    });
});

describe('append-only history', function () {
    it('cannot be edited or deleted', function () {
        $plan = prFixedPlan();
        $this->actingAs(prStaff(), 'admin')->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '100']]));
        $change = PlanPriceChange::first();

        expect(fn () => $change->forceFill(['new_price_kobo' => 1])->save())->toThrow(LogicException::class)
            ->and(fn () => $change->delete())->toThrow(LogicException::class)
            ->and(PlanPriceChange::first()->new_price_kobo)->toBe(10_000);
    });

    it('has no edit or delete routes for prices or history', function () {
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'prices') || str_contains($r->uri(), 'history') || str_contains($r->uri(), 'pricing'));

        expect($routes->filter(fn ($r) => in_array('DELETE', $r->methods(), true)))->toBeEmpty()
            ->and($routes->filter(fn ($r) => str_contains($r->uri(), 'history')))->toBeEmpty()
            ->and($routes->map->uri()->unique()->sort()->values()->all())->toBe([
                'admin/services/plans/{plan}/prices', 'admin/services/plans/{plan}/prices/edit', 'admin/services/plans/{plan}/prices/{userType}/status',
            ]);
    });
});

describe('permissions', function () {
    it('seeds pricing.view and pricing.update for Super Admin only', function () {
        expect(Role::findByName('super-admin', 'admin')->hasPermissionTo('pricing.view'))->toBeTrue()
            ->and(Role::findByName('super-admin', 'admin')->hasPermissionTo('pricing.update'))->toBeTrue();

        foreach ([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer] as $role) {
            $r = Role::findByName($role->value, 'admin');
            expect($r->hasPermissionTo('pricing.view'))->toBeFalse()->and($r->hasPermissionTo('pricing.update'))->toBeFalse();
        }
    });

    it('lists the Pricing permissions in the Roles & Permissions matrix', function () {
        $this->actingAs(prStaff(), 'admin')->get('/admin/roles/create')
            ->assertSee('value="pricing.view"', false)->assertSee('value="pricing.update"', false)->assertSee('Pricing');
    });

    it('lets Super Admin grant pricing permissions to another role', function () {
        $this->actingAs(prStaff(), 'admin')
            ->post('/admin/roles', ['name' => 'Pricing Desk', 'permissions' => ['admin.access', 'services.view', 'pricing.view']])->assertRedirect();
        $plan = prFixedPlan();

        $this->actingAs(prStaff('Pricing Desk'), 'admin')->get("/admin/services/plans/{$plan->id}/prices")->assertOk()->assertDontSee('Edit prices');
    });

    it('gives Super Admin full access', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Vendor, ['price_kobo' => 28_000]);
        $this->actingAs(prStaff(), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/prices")->assertOk()->assertSee('data-price-toggle="disable"', false);
        $this->get("/admin/services/plans/{$plan->id}/prices/edit")->assertOk();
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '290']]))->assertRedirect();
        $this->patch("/admin/services/plans/{$plan->id}/prices/vendor/status", ['is_active' => 0])->assertRedirect();
    });

    it('returns 403 on every pricing route for the other built-in roles', function (SystemRole $role) {
        $plan = prFixedPlan();
        $price = prPrice($plan, UserType::Vendor, ['price_kobo' => 28_000]);
        $this->actingAs(prStaff($role), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/prices")->assertForbidden();
        $this->get("/admin/services/plans/{$plan->id}/prices/edit")->assertForbidden();
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '1']]))->assertForbidden();
        $this->patch("/admin/services/plans/{$plan->id}/prices/vendor/status", ['is_active' => 0])->assertForbidden();

        expect($price->fresh()->price_kobo)->toBe(28_000)->and($price->fresh()->is_active)->toBeTrue()->and(PlanPriceChange::count())->toBe(0);
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('lets pricing.view only look, never change', function () {
        $plan = prFixedPlan();
        $price = prPrice($plan, UserType::Vendor, ['price_kobo' => 28_000]);
        $this->actingAs(prRole(['services.view', 'pricing.view']), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/prices?preview_type=vendor")->assertOk()->assertSee('Vendor pays ₦280.00')
            ->assertDontSee('Edit prices')->assertDontSee('data-price-toggle', false);
        $this->get("/admin/services/plans/{$plan->id}")->assertSee('Manage prices');
        $this->get('/admin/services/plans')->assertSee('data-priced="1/4"', false);
        $this->get("/admin/services/plans/{$plan->id}/prices/edit")->assertForbidden();
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '1']]))->assertForbidden();
        $this->patch("/admin/services/plans/{$plan->id}/prices/vendor/status", ['is_active' => 0])->assertForbidden();

        expect($price->fresh()->price_kobo)->toBe(28_000)->and($price->fresh()->is_active)->toBeTrue();
    });

    it('lets pricing.update (with services.view and pricing.view) change prices', function () {
        $plan = prFixedPlan();
        $this->actingAs(prRole(['services.view', 'pricing.view', 'pricing.update']), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/prices")->assertOk()->assertSee('Edit prices');
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '280']]))->assertRedirect();
        $this->patch("/admin/services/plans/{$plan->id}/prices/vendor/status", ['is_active' => 0])->assertRedirect();

        expect($plan->prices()->first()->price_kobo)->toBe(28_000)->and($plan->prices()->first()->is_active)->toBeFalse();
    });

    it('requires pricing.view and services.view alongside pricing.update', function () {
        $plan = prFixedPlan();

        $this->actingAs(prRole(['services.view', 'pricing.update']), 'admin');
        $this->get("/admin/services/plans/{$plan->id}/prices/edit")->assertForbidden();
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '1']]))->assertForbidden();

        $this->actingAs(prRole(['pricing.view', 'pricing.update']), 'admin');
        $this->get("/admin/services/plans/{$plan->id}/prices")->assertForbidden();
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '1']]))->assertForbidden();

        expect(PlanPrice::count())->toBe(0);
    });

    it('does not let services.* alone see or change prices', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Vendor, ['price_kobo' => 28_000]);
        $this->actingAs(prRole(['services.view', 'services.create', 'services.update', 'services.delete']), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/prices")->assertForbidden();
        $this->get("/admin/services/plans/{$plan->id}/prices/edit")->assertForbidden();
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '1']]))->assertForbidden();
        $this->get("/admin/services/plans/{$plan->id}")->assertOk()->assertDontSee('data-plan-pricing', false)->assertDontSee('Manage prices');
        $this->get('/admin/services/plans')->assertOk()->assertDontSee('data-priced', false)->assertDontSee('id="pricing"', false);
        $this->get('/admin/services/plans?pricing=missing')->assertOk()->assertSee("data-plan=\"{$plan->code}\"", false);

        expect($plan->prices()->first()->price_kobo)->toBe(28_000);
    });

    it('keeps guests and customers out', function () {
        $plan = prFixedPlan();
        prPrice($plan, UserType::Vendor, ['price_kobo' => 28_000]);

        $this->get("/admin/services/plans/{$plan->id}/prices")->assertRedirect(route('admin.login'));
        $this->put("/admin/services/plans/{$plan->id}/prices", prForm($plan, ['vendor' => ['price' => '1']]))->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create(), 'web');
        $this->get("/admin/services/plans/{$plan->id}/prices")->assertRedirect(route('admin.login'));
        $this->patch("/admin/services/plans/{$plan->id}/prices/vendor/status", ['is_active' => 0])->assertRedirect(route('admin.login'));

        expect($plan->prices()->first()->price_kobo)->toBe(28_000)->and($plan->prices()->first()->is_active)->toBeTrue();
    });

    it('re-checks authorization inside the pricing actions', function () {
        $plan = prFixedPlan();
        $price = prPrice($plan, UserType::Vendor, ['price_kobo' => 28_000]);
        $values = ['vendor' => ['price_kobo' => 1, 'discount_bps' => null, 'fee_kobo' => null]];

        foreach ([prStaff(SystemRole::Viewer), prStaff(SystemRole::Finance), prRole(['services.view', 'pricing.view']), prRole(['services.view', 'services.update'])] as $actor) {
            expect(fn () => app(SavePlanPrices::class)->handle($plan, $values, $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SetPlanPriceStatus::class)->handle($price, false, $actor))->toThrow(AuthorizationException::class);
        }

        $disabled = prStaff();
        $disabled->forceFill(['status' => 'disabled'])->save();
        expect(fn () => app(SavePlanPrices::class)->handle($plan, $values, $disabled))->toThrow(AuthorizationException::class)
            ->and($price->fresh()->price_kobo)->toBe(28_000)->and($price->fresh()->is_active)->toBeTrue();
    });
});

describe('scope', function () {
    it('seeds no prices', function () {
        $this->seed();

        expect(PlanPrice::count())->toBe(0)->and(PlanPriceChange::count())->toBe(0)->and(Plan::count())->toBe(0);
    });

    it('adds no wallet, purchase or customer-facing pricing code', function () {
        // Providers exist since Phase 7 (configuration only, in their own tables); provider cost never lives in pricing.
        // Wallets and transactions exist since Phase 8 (in their own tables); purchases and payments do not.
        foreach (['provider_routes', 'orders', 'payments', 'commissions', 'cashbacks'] as $table) {
            expect(Schema::hasTable($table))->toBeFalse("{$table} exists");
        }
        foreach (['App\\Models\\Order', 'App\\Services\\Pricing\\ProviderCost'] as $class) {
            expect(class_exists($class))->toBeFalse("{$class} exists");
        }

        $public = collect(Route::getRoutes())->filter(fn ($r) => ! str_starts_with($r->uri(), 'admin')
            && preg_match('/(price|pricing|plan|purchase|buy|quote)/i', $r->uri()));
        expect($public->map->uri()->values()->all())->toBe([]);
    });
});
