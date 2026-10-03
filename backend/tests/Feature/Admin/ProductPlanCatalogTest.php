<?php

use App\Actions\Admin\Catalog\SavePlan;
use App\Actions\Admin\Catalog\SaveProduct;
use App\Actions\Admin\Catalog\SetCatalogStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Catalog\AmountType;
use App\Support\Catalog\Network;
use App\Support\Catalog\ValidityPeriod;
use App\Support\Enums\SystemRole;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function ppStaff(SystemRole|string $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

/** Custom role holding exactly the given permissions (plus admin.access). */
function ppRole(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'PP '.implode(' ', $permissions), 'guard_name' => 'admin'])
        ->givePermissionTo(['admin.access', ...$permissions]);

    return ppStaff($role->name);
}

/** Active category → service → product (codes predictable). */
function ppProduct(array $attributes = [], string $serviceSlug = 'data'): Product
{
    $service = Service::factory()->create(['name' => ucfirst($serviceSlug), 'slug' => $serviceSlug]);

    return Product::factory()->create($attributes + ['service_id' => $service->id, 'name' => 'MTN', 'code' => $serviceSlug.'-mtn', 'network' => 'mtn']);
}

function ppPlan(array $attributes = [], ?Product $product = null): Plan
{
    $product ??= ppProduct();

    return Plan::factory()->create($attributes + ['product_id' => $product->id, 'name' => '1GB Monthly', 'code' => $product->code.'-1gb-monthly']);
}

describe('schema', function () {
    it('creates the products and plans tables with the structure columns', function () {
        expect(Schema::hasColumns('products', ['id', 'service_id', 'name', 'code', 'network', 'description', 'is_active', 'sort_order', 'created_at', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumns('plans', ['id', 'product_id', 'name', 'code', 'amount_type', 'validity_period', 'validity_days', 'data_volume_mb', 'description', 'is_active', 'sort_order', 'created_at', 'updated_at']))->toBeTrue();
    });

    it('has no price, money, provider or customer-type pricing columns', function () {
        foreach (['products', 'plans'] as $table) {
            foreach (['price', 'cost', 'amount', 'face_value', 'selling_price', 'cost_price', 'commission', 'cashback',
                'provider', 'provider_id', 'provider_code', 'api_code', 'customer_type', 'reseller_price', 'agent_price', 'data_type'] as $column) {
                expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} exists");
            }
        }

        // Customer selling prices live in their own plan_prices table (Phase 6), never on products or plans.
        expect(Schema::hasTable('providers'))->toBeFalse()->and(Schema::hasTable('provider_routes'))->toBeFalse()
            ->and(Schema::hasTable('orders'))->toBeFalse()
            ->and(Schema::hasTable('transactions'))->toBeFalse()->and(Schema::hasTable('wallets'))->toBeFalse();
    });

    it('relates category → service → product → plan', function () {
        $plan = ppPlan();
        $product = $plan->product;
        $service = $product->service;

        expect($product->plans->pluck('id')->all())->toBe([$plan->id])
            ->and($service->products->pluck('id')->all())->toBe([$product->id])
            ->and($plan->product->service->category->is($service->category))->toBeTrue()
            ->and($product->network)->toBe(Network::Mtn)
            ->and($plan->amount_type)->toBe(AmountType::Fixed);
    });

    it('refuses to delete a service with products or a product with plans at the database level', function () {
        $plan = ppPlan();

        expect(fn () => $plan->product->delete())->toThrow(QueryException::class)
            ->and(fn () => $plan->product->service->delete())->toThrow(QueryException::class);
    });

    it('keeps the fixed network list', function () {
        expect(array_map(fn (Network $n) => $n->value, Network::cases()))->toBe(['mtn', 'airtel', 'glo', '9mobile'])
            ->and(array_map(fn (Network $n) => $n->label(), Network::cases()))->toBe(['MTN', 'Airtel', 'Glo', '9mobile'])
            ->and(array_map(fn (AmountType $a) => $a->value, AmountType::cases()))->toBe(['fixed', 'variable'])
            ->and(array_map(fn (ValidityPeriod $v) => $v->value, ValidityPeriod::cases()))->toBe(['daily', 'weekly', 'monthly', 'other']);
    });
});

describe('products', function () {
    it('lists products with their service, network and plan count', function () {
        $plan = ppPlan();
        $this->actingAs(ppStaff(), 'admin');

        $this->get('/admin/services/products')->assertOk()
            ->assertSee('data-catalog-tab="products"', false)
            ->assertSee('data-product="data-mtn"', false)
            ->assertSee('MTN')->assertSee('Data')->assertSee('Add product');
        expect($plan->product->plans()->count())->toBe(1);
    });

    it('creates a product with a generated code and stays disabled unless enabled', function () {
        $service = Service::factory()->create(['name' => 'Data', 'slug' => 'data']);
        $this->actingAs(ppStaff(), 'admin');

        $this->get('/admin/services/products/create')->assertOk()->assertSee('9mobile');
        $response = $this->post('/admin/services/products', ['service_id' => $service->id, 'name' => 'MTN SME', 'network' => 'mtn', 'description' => 'SME bundles', 'sort_order' => 5]);

        $product = Product::firstWhere('code', 'data-mtn-sme');
        expect($product)->not->toBeNull()
            ->and($product->name)->toBe('MTN SME')->and($product->network)->toBe(Network::Mtn)
            ->and($product->is_active)->toBeFalse()->and($product->sort_order)->toBe(5)
            ->and($product->service->is($service))->toBeTrue();
        $response->assertRedirect(route('admin.services.products.show', $product));

        $this->post('/admin/services/products', ['service_id' => $service->id, 'name' => 'Airtel Gifting', 'network' => 'airtel', 'is_active' => 1]);
        expect(Product::firstWhere('code', 'data-airtel-gifting')->is_active)->toBeTrue();
    });

    it('allows a product without a network', function () {
        $service = Service::factory()->create(['name' => 'Smile Data', 'slug' => 'smile-data']);
        $this->actingAs(ppStaff(), 'admin');

        $this->post('/admin/services/products', ['service_id' => $service->id, 'name' => 'Smile'])->assertRedirect();

        expect(Product::firstWhere('code', 'smile-data-smile')->network)->toBeNull();
    });

    it('shows a product with its details and plans', function () {
        $plan = ppPlan(['data_volume_mb' => 1024]);
        $this->actingAs(ppStaff(), 'admin');

        $this->get("/admin/services/products/{$plan->product_id}")->assertOk()
            ->assertSee('data-product-details', false)
            ->assertSee('data-product-plan="data-mtn-1gb-monthly"', false)
            ->assertSee('Add plan')
            ->assertSee(route('admin.services.plans.create', ['product' => $plan->product_id]), false);
    });

    it('edits a product without changing its locked code', function () {
        $product = ppProduct();
        $other = Service::factory()->create(['name' => 'Airtime', 'slug' => 'airtime']);
        $this->actingAs(ppStaff(), 'admin');

        $this->get("/admin/services/products/{$product->id}/edit")->assertOk()->assertSee('data-mtn')->assertSee('locked after creation');
        $this->put("/admin/services/products/{$product->id}", [
            'service_id' => $other->id, 'name' => 'MTN Renamed', 'network' => 'glo', 'code' => 'hacked', 'is_active' => 0, 'description' => 'Changed',
        ])->assertRedirect(route('admin.services.products.show', $product));

        $product->refresh();
        expect($product->name)->toBe('MTN Renamed')->and($product->code)->toBe('data-mtn')
            ->and($product->network)->toBe(Network::Glo)->and($product->service_id)->toBe($other->id)
            ->and($product->description)->toBe('Changed')->and($product->is_active)->toBeTrue();
    });

    it('enables and disables a product only through the status route', function () {
        $product = ppProduct();
        $this->actingAs(ppStaff(), 'admin');

        $this->from('/admin/services/products')->patch("/admin/services/products/{$product->id}/status", ['is_active' => 0])
            ->assertRedirect('/admin/services/products')->assertSessionHas('status');
        expect($product->fresh()->is_active)->toBeFalse();

        $this->patch("/admin/services/products/{$product->id}/status", ['is_active' => 1]);
        expect($product->fresh()->is_active)->toBeTrue();

        $this->patch("/admin/services/products/{$product->id}/status", ['is_active' => 'maybe'])->assertSessionHasErrors('is_active');
    });

    it('rejects duplicate product codes within a service but allows the same name in another service', function () {
        $product = ppProduct();
        $airtime = Service::factory()->create(['name' => 'Airtime', 'slug' => 'airtime']);
        $this->actingAs(ppStaff(), 'admin');

        $this->post('/admin/services/products', ['service_id' => $product->service_id, 'name' => 'mtn!'])
            ->assertSessionHasErrors(['name' => 'This name is already used in this service (the code “data-mtn” is taken).']);
        $this->post('/admin/services/products', ['service_id' => $airtime->id, 'name' => 'MTN'])->assertSessionHasNoErrors();

        expect(Product::pluck('code')->sort()->values()->all())->toBe(['airtime-mtn', 'data-mtn'])
            ->and(fn () => Product::factory()->create(['code' => 'data-mtn']))->toThrow(QueryException::class);
    });

    it('validates product fields', function (array $input, string $field) {
        $service = Service::factory()->create(['name' => 'Data', 'slug' => 'data']);
        $this->actingAs(ppStaff(), 'admin');

        $this->post('/admin/services/products', $input + ['service_id' => $service->id, 'name' => 'Valid Name'])->assertSessionHasErrors($field);
        expect(Product::count())->toBe(0);
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'short name' => [['name' => 'A'], 'name'],
        'long name' => [['name' => str_repeat('a', 101)], 'name'],
        'symbol-only name' => [['name' => '-- !!'], 'name'],
        'unknown network' => [['network' => 'etisalat'], 'network'],
        'unknown service' => [['service_id' => 99999], 'service_id'],
        'negative sort order' => [['sort_order' => -1], 'sort_order'],
        'long description' => [['description' => str_repeat('a', 1001)], 'description'],
    ]);

    it('searches, filters and paginates products', function () {
        $data = Service::factory()->create(['name' => 'Data', 'slug' => 'data']);
        $airtime = Service::factory()->create(['name' => 'Airtime', 'slug' => 'airtime']);
        Product::factory()->create(['service_id' => $data->id, 'name' => 'MTN SME', 'code' => 'data-mtn-sme', 'network' => 'mtn']);
        Product::factory()->disabled()->create(['service_id' => $data->id, 'name' => 'Glo Gifting', 'code' => 'data-glo-gifting', 'network' => 'glo']);
        Product::factory()->create(['service_id' => $airtime->id, 'name' => 'Airtel', 'code' => 'airtime-airtel', 'network' => 'airtel']);
        $this->actingAs(ppStaff(), 'admin');

        $this->get('/admin/services/products?q=sme')->assertSee('data-product="data-mtn-sme"', false)->assertDontSee('data-product="data-glo-gifting"', false);
        $this->get('/admin/services/products?q=airtime-')->assertSee('data-product="airtime-airtel"', false)->assertDontSee('data-product="data-mtn-sme"', false);
        $this->get("/admin/services/products?service={$airtime->id}")->assertSee('data-product="airtime-airtel"', false)->assertDontSee('data-product="data-mtn-sme"', false);
        $this->get('/admin/services/products?network=glo')->assertSee('data-product="data-glo-gifting"', false)->assertDontSee('data-product="airtime-airtel"', false);
        $this->get('/admin/services/products?status=disabled')->assertSee('data-product="data-glo-gifting"', false)->assertDontSee('data-product="data-mtn-sme"', false);
        $this->get('/admin/services/products?status=active')->assertSee('data-product="data-mtn-sme"', false)->assertDontSee('data-product="data-glo-gifting"', false);
        $this->get('/admin/services/products?q=zzz-nothing')->assertSee('No products found');
        $this->get('/admin/services/products?network=etisalat')->assertSessionHasErrors('network');

        Product::factory()->count(16)->create(['service_id' => $data->id]);
        $this->get('/admin/services/products')->assertSee('page=2', false);
        $this->get('/admin/services/products?page=2')->assertOk();
    });
});

describe('plans', function () {
    it('lists plans with their product, volume and validity', function () {
        ppPlan(['data_volume_mb' => 1024, 'validity_period' => 'monthly', 'validity_days' => 30]);
        $this->actingAs(ppStaff(), 'admin');

        $this->get('/admin/services/plans')->assertOk()
            ->assertSee('data-catalog-tab="plans"', false)
            ->assertSee('data-plan="data-mtn-1gb-monthly"', false)
            ->assertSee('1 GB')->assertSee('Monthly · 30 days')->assertSee('Add plan');
    });

    it('creates a fixed plan with a code generated from the product code', function () {
        $product = ppProduct();
        $this->actingAs(ppStaff(), 'admin');

        $this->get("/admin/services/plans/create?product={$product->id}")->assertOk()->assertSee('Data · MTN');
        $response = $this->post('/admin/services/plans', [
            'product_id' => $product->id, 'name' => '500MB Weekly', 'amount_type' => 'fixed',
            'validity_period' => 'weekly', 'validity_days' => 7, 'data_volume_mb' => 500,
        ]);

        $plan = Plan::firstWhere('code', 'data-mtn-500mb-weekly');
        expect($plan)->not->toBeNull()
            ->and($plan->product->is($product))->toBeTrue()
            ->and($plan->amount_type)->toBe(AmountType::Fixed)->and($plan->validity_period)->toBe(ValidityPeriod::Weekly)
            ->and($plan->validity_days)->toBe(7)->and($plan->data_volume_mb)->toBe(500)
            ->and($plan->is_active)->toBeFalse()
            ->and($plan->dataVolumeLabel())->toBe('500 MB')->and($plan->validityLabel())->toBe('Weekly · 7 days');
        $response->assertRedirect(route('admin.services.plans.show', $plan));
    });

    it('creates a variable-amount plan without a data volume', function () {
        $product = ppProduct(['name' => 'MTN', 'code' => 'airtime-mtn'], 'airtime');
        $this->actingAs(ppStaff(), 'admin');

        $this->post('/admin/services/plans', ['product_id' => $product->id, 'name' => 'VTU', 'amount_type' => 'variable', 'is_active' => 1])->assertSessionHasNoErrors();

        $plan = Plan::firstWhere('code', 'airtime-mtn-vtu');
        expect($plan->amount_type)->toBe(AmountType::Variable)->and($plan->data_volume_mb)->toBeNull()
            ->and($plan->is_active)->toBeTrue()->and($plan->validityLabel())->toBeNull();
    });

    it('shows a plan with its details', function () {
        $plan = ppPlan(['data_volume_mb' => 1536, 'validity_period' => 'monthly', 'validity_days' => 30]);
        $this->actingAs(ppStaff(), 'admin');

        $this->get("/admin/services/plans/{$plan->id}")->assertOk()
            ->assertSee('data-plan-details', false)->assertSee('1.5 GB')->assertSee('Monthly · 30 days')
            ->assertSee('data-mtn-1gb-monthly')->assertSee('Fixed');
    });

    it('edits a plan without changing its locked code', function () {
        $plan = ppPlan();
        $this->actingAs(ppStaff(), 'admin');

        $this->get("/admin/services/plans/{$plan->id}/edit")->assertOk()->assertSee('locked after creation');
        $this->put("/admin/services/plans/{$plan->id}", [
            'product_id' => $plan->product_id, 'name' => '2GB Monthly', 'code' => 'hacked', 'amount_type' => 'fixed',
            'validity_period' => 'monthly', 'validity_days' => 30, 'data_volume_mb' => 2048, 'is_active' => 0,
        ])->assertRedirect(route('admin.services.plans.show', $plan));

        $plan->refresh();
        expect($plan->name)->toBe('2GB Monthly')->and($plan->code)->toBe('data-mtn-1gb-monthly')
            ->and($plan->data_volume_mb)->toBe(2048)->and($plan->is_active)->toBeTrue();
    });

    it('moves a plan to another product while keeping its code', function () {
        $plan = ppPlan();
        $target = Product::factory()->create(['service_id' => $plan->product->service_id, 'name' => 'MTN SME', 'code' => 'data-mtn-sme', 'network' => 'mtn']);
        $this->actingAs(ppStaff(), 'admin');

        $this->put("/admin/services/plans/{$plan->id}", ['product_id' => $target->id, 'name' => $plan->name, 'amount_type' => 'fixed'])->assertSessionHasNoErrors();

        expect($plan->fresh()->product_id)->toBe($target->id)->and($plan->fresh()->code)->toBe('data-mtn-1gb-monthly')
            ->and($target->plans()->count())->toBe(1);
        $this->put("/admin/services/plans/{$plan->id}", ['product_id' => 99999, 'name' => $plan->name, 'amount_type' => 'fixed'])->assertSessionHasErrors('product_id');
    });

    it('enables and disables a plan only through the status route', function () {
        $plan = ppPlan();
        $this->actingAs(ppStaff(), 'admin');

        $this->patch("/admin/services/plans/{$plan->id}/status", ['is_active' => 0])->assertRedirect()->assertSessionHas('status');
        expect($plan->fresh()->is_active)->toBeFalse();
        $this->patch("/admin/services/plans/{$plan->id}/status", ['is_active' => 1]);
        expect($plan->fresh()->is_active)->toBeTrue();
    });

    it('rejects duplicate plan codes within a product but allows the same name in another product', function () {
        $plan = ppPlan();
        $glo = Product::factory()->create(['service_id' => $plan->product->service_id, 'name' => 'Glo', 'code' => 'data-glo', 'network' => 'glo']);
        $this->actingAs(ppStaff(), 'admin');

        $this->post('/admin/services/plans', ['product_id' => $plan->product_id, 'name' => '1GB  monthly', 'amount_type' => 'fixed'])
            ->assertSessionHasErrors(['name' => 'This name is already used in this product (the code “data-mtn-1gb-monthly” is taken).']);
        $this->post('/admin/services/plans', ['product_id' => $glo->id, 'name' => '1GB Monthly', 'amount_type' => 'fixed'])->assertSessionHasNoErrors();

        expect(Plan::pluck('code')->sort()->values()->all())->toBe(['data-glo-1gb-monthly', 'data-mtn-1gb-monthly'])
            ->and(fn () => Plan::factory()->create(['code' => 'data-glo-1gb-monthly']))->toThrow(QueryException::class);
    });

    it('validates plan fields', function (array $input, string $field) {
        $product = ppProduct();
        $this->actingAs(ppStaff(), 'admin');

        $this->post('/admin/services/plans', $input + ['product_id' => $product->id, 'name' => 'Valid Plan', 'amount_type' => 'fixed'])->assertSessionHasErrors($field);
        expect(Plan::count())->toBe(0);
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'symbol-only name' => [['name' => '-- !!'], 'name'],
        'long name' => [['name' => str_repeat('a', 151)], 'name'],
        'unknown product' => [['product_id' => 99999], 'product_id'],
        'missing amount type' => [['amount_type' => ''], 'amount_type'],
        'unknown amount type' => [['amount_type' => 'free'], 'amount_type'],
        'unknown validity' => [['validity_period' => 'yearly'], 'validity_period'],
        'zero validity days' => [['validity_days' => 0], 'validity_days'],
        'too many validity days' => [['validity_days' => 3651], 'validity_days'],
        'text validity days' => [['validity_days' => 'thirty'], 'validity_days'],
        'zero data volume' => [['data_volume_mb' => 0], 'data_volume_mb'],
        'huge data volume' => [['data_volume_mb' => 10000001], 'data_volume_mb'],
        'fractional data volume' => [['data_volume_mb' => 1.5], 'data_volume_mb'],
        'variable with data volume' => [['amount_type' => 'variable', 'data_volume_mb' => 1024], 'data_volume_mb'],
    ]);

    it('explains the variable-amount data volume rule', function () {
        $product = ppProduct();
        $this->actingAs(ppStaff(), 'admin');

        $this->post('/admin/services/plans', ['product_id' => $product->id, 'name' => 'Any', 'amount_type' => 'variable', 'data_volume_mb' => 100])
            ->assertSessionHasErrors(['data_volume_mb' => 'Variable-amount plans cannot have a fixed data volume.']);
    });

    it('searches, filters and paginates plans', function () {
        $mtn = ppProduct();
        $glo = Product::factory()->create(['service_id' => $mtn->service_id, 'name' => 'Glo', 'code' => 'data-glo', 'network' => 'glo']);
        $airtime = ppProduct(['name' => 'Airtel', 'code' => 'airtime-airtel', 'network' => 'airtel'], 'airtime');
        ppPlan(['name' => '1GB Monthly', 'code' => 'data-mtn-1gb-monthly', 'validity_period' => 'monthly'], $mtn);
        ppPlan(['name' => '100MB Daily', 'code' => 'data-glo-100mb-daily', 'validity_period' => 'daily', 'is_active' => false], $glo);
        ppPlan(['name' => 'VTU', 'code' => 'airtime-airtel-vtu', 'amount_type' => 'variable'], $airtime);
        $this->actingAs(ppStaff(), 'admin');

        $this->get('/admin/services/plans?q=100mb')->assertSee('data-plan="data-glo-100mb-daily"', false)->assertDontSee('data-plan="data-mtn-1gb-monthly"', false);
        $this->get("/admin/services/plans?service={$airtime->service_id}")->assertSee('data-plan="airtime-airtel-vtu"', false)->assertDontSee('data-plan="data-mtn-1gb-monthly"', false);
        $this->get("/admin/services/plans?product={$glo->id}")->assertSee('data-plan="data-glo-100mb-daily"', false)->assertDontSee('data-plan="data-mtn-1gb-monthly"', false);
        $this->get('/admin/services/plans?network=mtn')->assertSee('data-plan="data-mtn-1gb-monthly"', false)->assertDontSee('data-plan="airtime-airtel-vtu"', false);
        $this->get('/admin/services/plans?validity=daily')->assertSee('data-plan="data-glo-100mb-daily"', false)->assertDontSee('data-plan="data-mtn-1gb-monthly"', false);
        $this->get('/admin/services/plans?status=disabled')->assertSee('data-plan="data-glo-100mb-daily"', false)->assertDontSee('data-plan="airtime-airtel-vtu"', false);
        $this->get('/admin/services/plans?q=nothing-here')->assertSee('No plans found');
        $this->get('/admin/services/plans?validity=yearly')->assertSessionHasErrors('validity');

        Plan::factory()->count(16)->create(['product_id' => $mtn->id]);
        $this->get('/admin/services/plans')->assertSee('page=2', false);
        $this->get('/admin/services/plans?page=2')->assertOk();
    });
});

describe('availability', function () {
    it('makes a plan available only when plan, product, service and category are all active', function () {
        $plan = ppPlan();
        $product = $plan->product;
        $service = $product->service;
        $category = $service->category;
        $fresh = fn () => Plan::with('product.service.category')->find($plan->id);

        expect($fresh()->isAvailable())->toBeTrue()->and($fresh()->statusLabel())->toBe('Active')
            ->and(Plan::available()->count())->toBe(1)->and(Product::available()->count())->toBe(1);

        $category->forceFill(['is_active' => false])->save();
        expect($fresh()->isAvailable())->toBeFalse()->and($fresh()->statusLabel())->toBe('Active (category disabled)')
            ->and(Plan::available()->count())->toBe(0)->and(Product::available()->count())->toBe(0);

        $service->forceFill(['is_active' => false])->save();
        expect($fresh()->statusLabel())->toBe('Active (service disabled)');

        $product->forceFill(['is_active' => false])->save();
        expect($fresh()->statusLabel())->toBe('Active (product disabled)');

        $plan->forceFill(['is_active' => false])->save();
        expect($fresh()->statusLabel())->toBe('Disabled');

        // Each level keeps its own status: re-enabling parents does not re-enable the plan.
        $category->forceFill(['is_active' => true])->save();
        $service->forceFill(['is_active' => true])->save();
        $product->forceFill(['is_active' => true])->save();
        expect($fresh()->isAvailable())->toBeFalse()->and($fresh()->is_active)->toBeFalse()
            ->and(Product::available()->count())->toBe(1)->and(Plan::available()->count())->toBe(0);
    });

    it('preserves child statuses when a parent is disabled through the admin', function () {
        $plan = ppPlan();
        $this->actingAs(ppStaff(), 'admin');

        $this->patch("/admin/services/products/{$plan->product_id}/status", ['is_active' => 0]);
        $this->patch("/admin/services/{$plan->product->service_id}/status", ['is_active' => 0]);

        expect($plan->fresh()->is_active)->toBeTrue()->and($plan->product->fresh()->is_active)->toBeFalse();
        $this->get("/admin/services/plans/{$plan->id}")->assertSee('Active (product disabled)')->assertSee('data-available', false);
        $this->get('/admin/services/plans?status=available')->assertDontSee('data-plan="data-mtn-1gb-monthly"', false);
    });

    it('shows the unavailable reason on product pages and filters available products', function () {
        $product = ppProduct();
        $product->service->category->forceFill(['is_active' => false])->save();
        $this->actingAs(ppStaff(), 'admin');

        $this->get("/admin/services/products/{$product->id}")->assertSee('Active (category disabled)');
        $this->get('/admin/services/products?status=available')->assertDontSee('data-product="data-mtn"', false);

        $product->service->category->forceFill(['is_active' => true])->save();
        $this->get('/admin/services/products?status=available')->assertSee('data-product="data-mtn"', false);
    });
});

describe('services integration', function () {
    it('lists a service’s products on the service page', function () {
        $product = ppProduct();
        $this->actingAs(ppStaff(), 'admin');

        $this->get("/admin/services/{$product->service_id}")->assertOk()
            ->assertSee('data-service-products', false)
            ->assertSee('data-service-product="data-mtn"', false)
            ->assertSee(route('admin.services.products.create', ['service' => $product->service_id]), false);
    });

    it('shows four catalog tabs under the single Services sidebar item', function () {
        $this->actingAs(ppStaff(), 'admin');

        foreach (['/admin/services', '/admin/services/categories', '/admin/services/products', '/admin/services/plans'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('data-nav="services"', false)
                ->assertSee('data-catalog-tab="services"', false)->assertSee('data-catalog-tab="categories"', false)
                ->assertSee('data-catalog-tab="products"', false)->assertSee('data-catalog-tab="plans"', false);
        }
    });

    it('shows no prices, balances, providers or purchase actions to catalog-only staff', function () {
        // Prices (Phase 6) are visible only with pricing.view; services.* alone shows the structure only.
        $plan = ppPlan(['data_volume_mb' => 1024]);
        $this->actingAs(ppRole(['services.view', 'services.create', 'services.update']), 'admin');

        foreach (['/admin/services/products', "/admin/services/products/{$plan->product_id}", '/admin/services/products/create',
            '/admin/services/plans', "/admin/services/plans/{$plan->id}", '/admin/services/plans/create', "/admin/services/plans/{$plan->id}/edit"] as $url) {
            $html = mb_strtolower($this->get($url)->getContent());
            // Plan forms carry ₦ face-value limit fields (variable plans, Phase 6); those are limits, not prices.
            $words = ['price', 'balance', 'buy now', 'purchase now', 'checkout', 'provider:', 'name="cost', 'name="amount"'];
            foreach (str_contains($url, 'plans/create') || str_ends_with($url, '/edit') ? $words : ['₦', ...$words] as $word) {
                expect(str_contains($html, $word))->toBeFalse("{$url} contains {$word}");
            }
        }
    });
});

describe('authorization', function () {
    it('returns 403 on every product and plan route for the other built-in roles', function (SystemRole $role) {
        $plan = ppPlan();
        $product = $plan->product;
        $this->actingAs(ppStaff($role), 'admin');

        foreach (['/admin/services/products', '/admin/services/products/create', "/admin/services/products/{$product->id}", "/admin/services/products/{$product->id}/edit",
            '/admin/services/plans', '/admin/services/plans/create', "/admin/services/plans/{$plan->id}", "/admin/services/plans/{$plan->id}/edit"] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/admin/services/products', ['service_id' => $product->service_id, 'name' => 'Sneaky'])->assertForbidden();
        $this->put("/admin/services/products/{$product->id}", ['service_id' => $product->service_id, 'name' => 'Changed'])->assertForbidden();
        $this->patch("/admin/services/products/{$product->id}/status", ['is_active' => 0])->assertForbidden();
        $this->post('/admin/services/plans', ['product_id' => $product->id, 'name' => 'Sneaky', 'amount_type' => 'fixed'])->assertForbidden();
        $this->put("/admin/services/plans/{$plan->id}", ['product_id' => $product->id, 'name' => 'Changed', 'amount_type' => 'fixed'])->assertForbidden();
        $this->patch("/admin/services/plans/{$plan->id}/status", ['is_active' => 0])->assertForbidden();

        expect(Product::count())->toBe(1)->and(Plan::count())->toBe(1)
            ->and($product->fresh()->name)->toBe('MTN')->and($product->fresh()->is_active)->toBeTrue()
            ->and($plan->fresh()->name)->toBe('1GB Monthly')->and($plan->fresh()->is_active)->toBeTrue();
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('allows exactly what each services.* permission grants', function () {
        $plan = ppPlan();
        $product = $plan->product;

        $this->actingAs(ppRole(['services.view']), 'admin');
        $this->get('/admin/services/products')->assertOk()->assertDontSee('Add product')->assertDontSee('data-toggle=', false);
        $this->get('/admin/services/plans')->assertOk()->assertDontSee('Add plan')->assertDontSee('data-toggle=', false);
        $this->get("/admin/services/products/{$product->id}")->assertOk()->assertDontSee('Add plan')->assertDontSee('data-toggle=', false);
        $this->get("/admin/services/plans/{$plan->id}")->assertOk()->assertDontSee('data-toggle=', false);
        $this->get('/admin/services/products/create')->assertForbidden();
        $this->get('/admin/services/plans/create')->assertForbidden();
        $this->get("/admin/services/products/{$product->id}/edit")->assertForbidden();
        $this->get("/admin/services/plans/{$plan->id}/edit")->assertForbidden();

        $this->actingAs(ppRole(['services.view', 'services.create']), 'admin');
        $this->post('/admin/services/products', ['service_id' => $product->service_id, 'name' => 'Glo'])->assertRedirect();
        $this->post('/admin/services/plans', ['product_id' => $product->id, 'name' => '2GB', 'amount_type' => 'fixed'])->assertRedirect();
        $this->put("/admin/services/products/{$product->id}", ['service_id' => $product->service_id, 'name' => 'Nope'])->assertForbidden();
        $this->patch("/admin/services/plans/{$plan->id}/status", ['is_active' => 0])->assertForbidden();

        $this->actingAs(ppRole(['services.view', 'services.update']), 'admin');
        $this->patch("/admin/services/products/{$product->id}/status", ['is_active' => 0])->assertRedirect();
        $this->put("/admin/services/plans/{$plan->id}", ['product_id' => $product->id, 'name' => 'Updated Plan', 'amount_type' => 'fixed'])->assertRedirect();
        $this->post('/admin/services/products', ['service_id' => $product->service_id, 'name' => 'Airtel'])->assertForbidden();
        $this->post('/admin/services/plans', ['product_id' => $product->id, 'name' => '3GB', 'amount_type' => 'fixed'])->assertForbidden();

        expect(Product::where('code', 'data-glo')->exists())->toBeTrue()
            ->and(Plan::where('code', 'data-mtn-2gb')->exists())->toBeTrue()
            ->and(Product::where('code', 'data-airtel')->exists())->toBeFalse()
            ->and(Plan::where('code', 'data-mtn-3gb')->exists())->toBeFalse()
            ->and($product->fresh()->is_active)->toBeFalse()->and($product->fresh()->name)->toBe('MTN')
            ->and($plan->fresh()->name)->toBe('Updated Plan')->and($plan->fresh()->is_active)->toBeTrue();
    });

    it('requires services.view even with create or update permission', function () {
        $plan = ppPlan();

        $this->actingAs(ppRole(['services.create']), 'admin')->get('/admin/services/products/create')->assertForbidden();
        $this->actingAs(ppRole(['services.update']), 'admin')->get("/admin/services/plans/{$plan->id}/edit")->assertForbidden();
    });

    it('keeps guests and customers out', function () {
        $plan = ppPlan();

        $this->get('/admin/services/products')->assertRedirect(route('admin.login'));
        $this->get('/admin/services/plans')->assertRedirect(route('admin.login'));
        $this->post('/admin/services/plans', ['product_id' => $plan->product_id, 'name' => 'Guest', 'amount_type' => 'fixed'])->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/services/products')->assertRedirect(route('admin.login'));
        $this->get("/admin/services/plans/{$plan->id}")->assertRedirect(route('admin.login'));
        $this->patch("/admin/services/products/{$plan->product_id}/status", ['is_active' => 0])->assertRedirect(route('admin.login'));
        $this->patch("/admin/services/plans/{$plan->id}/status", ['is_active' => 0])->assertRedirect(route('admin.login'));

        expect($plan->fresh()->is_active)->toBeTrue()->and($plan->product->fresh()->is_active)->toBeTrue()->and(Plan::count())->toBe(1);
    });

    it('re-checks permissions inside the actions', function () {
        $viewer = ppStaff(SystemRole::Viewer);
        $plan = ppPlan();
        $product = $plan->product;

        expect(fn () => app(SaveProduct::class)->create(['service_id' => $product->service_id, 'name' => 'X'], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveProduct::class)->update($product, ['service_id' => $product->service_id, 'name' => 'X'], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SavePlan::class)->create(['product_id' => $product->id, 'name' => 'X', 'amount_type' => 'fixed'], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SavePlan::class)->update($plan, ['product_id' => $product->id, 'name' => 'X', 'amount_type' => 'fixed'], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetCatalogStatus::class)->handle($product, false, $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetCatalogStatus::class)->handle($plan, false, $viewer))->toThrow(AuthorizationException::class);
    });

    it('adds no plans.* or products.* permissions', function () {
        $names = Permission::pluck('name');

        expect($names->filter(fn ($n) => str_starts_with($n, 'plans.') || str_starts_with($n, 'products.'))->all())->toBe([]);
    });
});

describe('no delete', function () {
    it('has no delete routes for products or plans', function () {
        $deletes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'admin/services') && in_array('DELETE', $r->methods(), true));

        expect($deletes)->toBeEmpty();
    });

    it('rejects DELETE requests and keeps the records', function () {
        $plan = ppPlan();
        $this->actingAs(ppStaff(), 'admin');

        $this->delete("/admin/services/products/{$plan->product_id}")->assertMethodNotAllowed();
        $this->delete("/admin/services/plans/{$plan->id}")->assertMethodNotAllowed();

        expect(Product::count())->toBe(1)->and(Plan::count())->toBe(1);
    });

    it('offers no delete actions or buttons', function () {
        $plan = ppPlan();
        $this->actingAs(ppStaff(), 'admin');

        expect(method_exists(SaveProduct::class, 'delete'))->toBeFalse()
            ->and(method_exists(SavePlan::class, 'delete'))->toBeFalse()
            ->and(class_exists('App\\Actions\\Admin\\Catalog\\DeleteProduct'))->toBeFalse()
            ->and(class_exists('App\\Actions\\Admin\\Catalog\\DeletePlan'))->toBeFalse();
        $this->get("/admin/services/plans/{$plan->id}")->assertDontSee('name="_method" value="DELETE"', false);
        $this->get("/admin/services/products/{$plan->product_id}")->assertDontSee('name="_method" value="DELETE"', false);
    });
});

describe('seeding', function () {
    it('seeds the generic products disabled with stable codes and no plans', function () {
        $this->seed(ServiceCatalogSeeder::class);
        $this->seed(ProductCatalogSeeder::class);

        expect(Product::orderBy('code')->pluck('code')->all())->toBe([
            'airtime-9mobile', 'airtime-airtel', 'airtime-glo', 'airtime-mtn',
            'data-9mobile', 'data-airtel', 'data-glo', 'data-mtn', 'smile-data-smile',
        ])
            ->and(Product::where('is_active', true)->count())->toBe(0)
            ->and(Plan::count())->toBe(0)
            ->and(Product::firstWhere('code', 'smile-data-smile')->network)->toBeNull()
            ->and(Product::firstWhere('code', 'data-9mobile')->network)->toBe(Network::NineMobile)
            ->and(Product::firstWhere('code', 'airtime-mtn')->service->slug)->toBe('airtime');
    });

    it('is idempotent and never overwrites admin edits', function () {
        $this->seed(ServiceCatalogSeeder::class);
        $this->seed(ProductCatalogSeeder::class);
        $product = Product::firstWhere('code', 'data-mtn');
        $product->forceFill(['name' => 'MTN SME', 'description' => 'Edited', 'sort_order' => 99, 'is_active' => true])->save();

        $this->seed(ProductCatalogSeeder::class);
        $this->seed(ProductCatalogSeeder::class);

        $product->refresh();
        expect(Product::count())->toBe(9)->and(Plan::count())->toBe(0)
            ->and($product->name)->toBe('MTN SME')->and($product->description)->toBe('Edited')
            ->and($product->sort_order)->toBe(99)->and($product->is_active)->toBeTrue();
    });

    it('skips products whose service is missing', function () {
        Service::factory()->create(['name' => 'Data', 'slug' => 'data']);

        $this->seed(ProductCatalogSeeder::class);

        expect(Product::pluck('code')->sort()->values()->all())->toBe(['data-9mobile', 'data-airtel', 'data-glo', 'data-mtn']);
    });

    it('runs from the database seeder', function () {
        $this->seed();

        expect(Product::count())->toBe(9)->and(Plan::count())->toBe(0)
            ->and(ServiceCategory::count())->toBe(5)->and(Service::count())->toBe(13);
    });
});
