<?php

use App\Actions\Admin\Catalog\SaveCategory;
use App\Actions\Admin\Catalog\SaveService;
use App\Actions\Admin\Catalog\SetCatalogStatus;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Catalog\CatalogIcon;
use App\Support\Enums\SystemRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function catalogStaff(SystemRole|string $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

/** Custom role holding exactly the given permissions (plus admin.access). */
function catalogRole(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'Catalog '.implode(' ', $permissions), 'guard_name' => 'admin'])
        ->givePermissionTo(['admin.access', ...$permissions]);

    return catalogStaff($role->name);
}

function category(array $attributes = []): ServiceCategory
{
    return ServiceCategory::factory()->create($attributes);
}

function catalogService(array $attributes = []): Service
{
    return Service::factory()->create($attributes);
}

describe('schema', function () {
    it('creates the categories and services tables with the required columns', function () {
        expect(Schema::hasColumns('service_categories', ['id', 'name', 'slug', 'description', 'icon', 'is_active', 'sort_order', 'created_at', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumns('services', ['id', 'category_id', 'name', 'slug', 'description', 'icon', 'is_active', 'sort_order', 'created_at', 'updated_at']))->toBeTrue();
    });

    it('relates services to categories', function () {
        $category = category(['name' => 'Telecom X', 'slug' => 'telecom-x']);
        $service = catalogService(['category_id' => $category->id]);

        expect($service->category->is($category))->toBeTrue()
            ->and($category->services->pluck('id')->all())->toBe([$service->id]);
    });

    it('refuses to delete a category that still has services at the database level', function () {
        $service = catalogService();

        expect(fn () => $service->category->delete())->toThrow(QueryException::class);
    });
});

describe('authorization', function () {
    it('lets super admin open every catalog page', function () {
        $service = catalogService();
        $this->actingAs(catalogStaff(), 'admin');

        foreach (['/admin/services', '/admin/services/create', "/admin/services/{$service->id}", "/admin/services/{$service->id}/edit",
            '/admin/services/categories', '/admin/services/categories/create', "/admin/services/categories/{$service->category_id}",
            "/admin/services/categories/{$service->category_id}/edit"] as $url) {
            $this->get($url)->assertOk();
        }
    });

    it('returns 403 on every catalog route for the other built-in roles', function (SystemRole $role) {
        $service = catalogService(['name' => 'Original', 'slug' => 'original']);
        $category = $service->category;
        $this->actingAs(catalogStaff($role), 'admin');

        $this->get('/admin/services')->assertForbidden();
        $this->get('/admin/services/categories')->assertForbidden();
        $this->get("/admin/services/{$service->id}")->assertForbidden();
        $this->get("/admin/services/categories/{$category->id}")->assertForbidden();
        $this->get('/admin/services/create')->assertForbidden();
        $this->post('/admin/services', ['category_id' => $category->id, 'name' => 'Sneaky'])->assertForbidden();
        $this->post('/admin/services/categories', ['name' => 'Sneaky Cat'])->assertForbidden();
        $this->put("/admin/services/{$service->id}", ['category_id' => $category->id, 'name' => 'Changed'])->assertForbidden();
        $this->put("/admin/services/categories/{$category->id}", ['name' => 'Changed'])->assertForbidden();
        $this->patch("/admin/services/{$service->id}/status", ['is_active' => 0])->assertForbidden();
        $this->patch("/admin/services/categories/{$category->id}/status", ['is_active' => 0])->assertForbidden();

        expect(Service::count())->toBe(1)->and($service->fresh()->name)->toBe('Original')->and($service->fresh()->is_active)->toBeTrue()
            ->and(ServiceCategory::count())->toBe(1)->and($category->fresh()->is_active)->toBeTrue();
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('allows exactly what each services.* permission grants', function () {
        $service = catalogService();
        $category = $service->category;

        $viewer = catalogRole(['services.view']);
        $this->actingAs($viewer, 'admin');
        $this->get('/admin/services')->assertOk()->assertDontSee('Add service')->assertDontSee('data-toggle=', false);
        $this->get('/admin/services/categories')->assertOk()->assertDontSee('Add category');
        $this->get('/admin/services/create')->assertForbidden();
        $this->get("/admin/services/{$service->id}/edit")->assertForbidden();
        $this->patch("/admin/services/{$service->id}/status", ['is_active' => 0])->assertForbidden();

        $creator = catalogRole(['services.view', 'services.create']);
        $this->actingAs($creator, 'admin');
        $this->post('/admin/services/categories', ['name' => 'Creator Cat'])->assertRedirect();
        $this->put("/admin/services/categories/{$category->id}", ['name' => 'Nope'])->assertForbidden();

        $updater = catalogRole(['services.view', 'services.update']);
        $this->actingAs($updater, 'admin');
        $this->patch("/admin/services/{$service->id}/status", ['is_active' => 0])->assertRedirect();
        $this->post('/admin/services', ['category_id' => $category->id, 'name' => 'Nope Service'])->assertForbidden();

        expect($service->fresh()->is_active)->toBeFalse()
            ->and(ServiceCategory::where('slug', 'creator-cat')->exists())->toBeTrue()
            ->and(Service::where('slug', 'nope-service')->exists())->toBeFalse();
    });

    it('requires services.view even with create permission', function () {
        $this->actingAs(catalogRole(['services.create']), 'admin')->get('/admin/services/create')->assertForbidden();
    });

    it('keeps guests and customers out', function () {
        $service = catalogService();

        $this->get('/admin/services')->assertRedirect(route('admin.login'));
        $this->post('/admin/services/categories', ['name' => 'Guest Cat'])->assertRedirect(route('admin.login'));

        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/admin/services')->assertRedirect(route('admin.login'));
        $this->get('/admin/services/categories')->assertRedirect(route('admin.login'));
        $this->patch("/admin/services/{$service->id}/status", ['is_active' => 0])->assertRedirect(route('admin.login'));

        expect($service->fresh()->is_active)->toBeTrue()->and(ServiceCategory::where('slug', 'guest-cat')->exists())->toBeFalse();
    });

    it('signs out deactivated staff', function () {
        $super = catalogStaff();
        $this->actingAs($super, 'admin');
        $super->forceFill(['status' => 'disabled'])->save();

        $this->get('/admin/services')->assertRedirect(route('admin.login'));
    });

    it('re-checks permissions inside the actions', function () {
        $viewer = catalogStaff(SystemRole::Viewer);
        $service = catalogService();

        expect(fn () => app(SaveCategory::class)->create(['name' => 'X Cat'], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SaveService::class)->update($service, ['category_id' => $service->category_id, 'name' => 'X'], $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetCatalogStatus::class)->handle($service, false, $viewer))->toThrow(AuthorizationException::class);
    });

    it('shows the Services sidebar item with Services and Categories tabs to super admin only', function () {
        $this->actingAs(catalogStaff(), 'admin')->get('/admin/services')
            ->assertSee('data-nav="services"', false)
            ->assertSee('data-catalog-tab="services"', false)
            ->assertSee('data-catalog-tab="categories"', false)
            ->assertDontSee('is not built yet');

        $this->actingAs(catalogStaff(SystemRole::Manager), 'admin')->get('/admin')->assertDontSee('data-nav="services"', false);
    });
});

describe('no delete', function () {
    it('has no delete routes for categories or services', function () {
        $deletes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'admin/services') && in_array('DELETE', $r->methods(), true));

        expect($deletes)->toBeEmpty();
    });

    it('rejects DELETE requests and keeps the records', function () {
        $service = catalogService();
        $this->actingAs(catalogStaff(), 'admin');

        $this->delete("/admin/services/{$service->id}")->assertMethodNotAllowed();
        $this->delete("/admin/services/categories/{$service->category_id}")->assertMethodNotAllowed();

        expect(Service::count())->toBe(1)->and(ServiceCategory::count())->toBe(1);
    });

    it('offers no delete actions', function () {
        expect(method_exists(SaveCategory::class, 'delete'))->toBeFalse()
            ->and(method_exists(SaveService::class, 'delete'))->toBeFalse()
            ->and(class_exists('App\\Actions\\Admin\\Catalog\\DeleteService'))->toBeFalse()
            ->and(class_exists('App\\Actions\\Admin\\Catalog\\DeleteCategory'))->toBeFalse();
    });
});

describe('categories', function () {
    it('creates a category with a generated slug', function () {
        $this->actingAs(catalogStaff(), 'admin')
            ->post('/admin/services/categories', ['name' => 'Bills & Utilities Plus', 'description' => 'Bills.', 'icon' => 'card', 'sort_order' => 5, 'is_active' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Category “Bills & Utilities Plus” created.');

        $category = ServiceCategory::firstWhere('slug', 'bills-and-utilities-plus');
        expect($category->name)->toBe('Bills & Utilities Plus')
            ->and($category->icon)->toBe(CatalogIcon::Card)
            ->and($category->is_active)->toBeTrue()
            ->and($category->sort_order)->toBe(5);
    });

    it('creates categories disabled unless marked active', function () {
        $this->actingAs(catalogStaff(), 'admin')->post('/admin/services/categories', ['name' => 'Quiet Cat', 'is_active' => '0']);

        expect(ServiceCategory::firstWhere('slug', 'quiet-cat')->is_active)->toBeFalse();
    });

    it('ignores a submitted slug and keeps the slug locked on edit', function () {
        $this->actingAs(catalogStaff(), 'admin')->post('/admin/services/categories', ['name' => 'Locked Cat', 'slug' => 'hacked-slug']);
        $category = ServiceCategory::firstWhere('name', 'Locked Cat');
        expect($category->slug)->toBe('locked-cat');

        $this->put("/admin/services/categories/{$category->id}", ['name' => 'Renamed Cat', 'slug' => 'new-slug', 'is_active' => '0'])
            ->assertSessionHasNoErrors();

        $category->refresh();
        expect($category->name)->toBe('Renamed Cat')->and($category->slug)->toBe('locked-cat')->and($category->is_active)->toBeFalse();
        $this->get("/admin/services/categories/{$category->id}/edit")->assertSee('locked after creation');
    });

    it('rejects duplicate slugs', function () {
        category(['name' => 'Telecom', 'slug' => 'telecom']);

        $this->actingAs(catalogStaff(), 'admin')->post('/admin/services/categories', ['name' => 'TELECOM'])
            ->assertSessionHasErrors(['name' => 'This name is already used (the slug “telecom” is taken).']);
        $this->post('/admin/services/categories', ['name' => '  telecom  '])->assertSessionHasErrors('name');

        expect(ServiceCategory::count())->toBe(1);
    });

    it('validates category input', function (array $payload, string $field) {
        $this->actingAs(catalogStaff(), 'admin')->post('/admin/services/categories', $payload)->assertSessionHasErrors($field);
        expect(ServiceCategory::count())->toBe(0);
    })->with([
        'missing name' => [['name' => ''], 'name'],
        'too short' => [['name' => 'a'], 'name'],
        'no letters or numbers' => [['name' => '-- !!'], 'name'],
        'too long' => [['name' => str_repeat('a', 101)], 'name'],
        'free-text icon' => [['name' => 'Icon Cat', 'icon' => '<svg onload=alert(1)>'], 'icon'],
        'negative order' => [['name' => 'Order Cat', 'sort_order' => -1], 'sort_order'],
        'long description' => [['name' => 'Desc Cat', 'description' => str_repeat('x', 1001)], 'description'],
    ]);

    it('lists, searches, filters and paginates categories', function () {
        $this->actingAs(catalogStaff(), 'admin');
        category(['name' => 'Alpha Group', 'slug' => 'alpha-group', 'sort_order' => 1]);
        category(['name' => 'Beta Group', 'slug' => 'beta-group', 'sort_order' => 2, 'is_active' => false]);
        foreach (range(1, 16) as $i) {
            category(['name' => "Zeta {$i}", 'slug' => "zeta-{$i}", 'sort_order' => 100]);
        }

        $this->get('/admin/services/categories')->assertSeeInOrder(['Alpha Group', 'Beta Group'])->assertSee('Showing 1–15 of 18');
        $this->get('/admin/services/categories?page=2')->assertSee('Showing 16–18 of 18');
        $this->get('/admin/services/categories?q=beta')->assertSee('data-category="beta-group"', false)->assertDontSee('data-category="alpha-group"', false);
        $this->get('/admin/services/categories?q=alpha-gr')->assertSee('data-category="alpha-group"', false);
        $this->get('/admin/services/categories?status=disabled')->assertSee('data-category="beta-group"', false)->assertDontSee('data-category="alpha-group"', false);
        $this->get('/admin/services/categories?q=nothing-here')->assertSee('No categories found');
        $this->get('/admin/services/categories?status=weird')->assertSessionHasErrors('status');
    });

    it('shows a category with its services', function () {
        $category = category(['name' => 'Identity', 'slug' => 'identity']);
        catalogService(['category_id' => $category->id, 'name' => 'NIN Lookup', 'slug' => 'nin-lookup']);

        $this->actingAs(catalogStaff(), 'admin')->get("/admin/services/categories/{$category->id}")->assertOk()
            ->assertSee('data-category-details', false)->assertSee('identity')
            ->assertSee('data-category-service="nin-lookup"', false);
    });

    it('enables and disables a category', function () {
        $category = category();
        $this->actingAs(catalogStaff(), 'admin');

        $this->patch("/admin/services/categories/{$category->id}/status", ['is_active' => 0])->assertSessionHas('status');
        expect($category->fresh()->is_active)->toBeFalse();

        $this->patch("/admin/services/categories/{$category->id}/status", ['is_active' => 1]);
        expect($category->fresh()->is_active)->toBeTrue();

        $this->patch("/admin/services/categories/{$category->id}/status", ['is_active' => 'maybe'])->assertSessionHasErrors('is_active');
    });

    it('returns 404 for unknown categories', function () {
        $this->actingAs(catalogStaff(), 'admin')->get('/admin/services/categories/999999')->assertNotFound();
    });
});

describe('services', function () {
    it('creates a service in a category with a generated slug', function () {
        $category = category(['name' => 'Telecom', 'slug' => 'telecom']);

        $this->actingAs(catalogStaff(), 'admin')
            ->post('/admin/services', ['category_id' => $category->id, 'name' => 'Smile Data', 'description' => 'Smile network.', 'icon' => 'server', 'sort_order' => 50])
            ->assertSessionHasNoErrors();

        $service = Service::firstWhere('slug', 'smile-data');
        expect($service->category_id)->toBe($category->id)
            ->and($service->icon)->toBe(CatalogIcon::Server)
            ->and($service->is_active)->toBeFalse()
            ->and($service->sort_order)->toBe(50);
    });

    it('ignores a submitted slug and keeps it locked on edit, including after renaming and moving category', function () {
        $first = category();
        $second = category();
        $this->actingAs(catalogStaff(), 'admin')->post('/admin/services', ['category_id' => $first->id, 'name' => 'Exam Pin', 'slug' => 'evil']);
        $service = Service::firstWhere('name', 'Exam Pin');
        expect($service->slug)->toBe('exam-pin');

        $this->put("/admin/services/{$service->id}", ['category_id' => $second->id, 'name' => 'Exam PIN Cards', 'slug' => 'changed', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $service->refresh();
        expect($service->name)->toBe('Exam PIN Cards')->and($service->slug)->toBe('exam-pin')
            ->and($service->category_id)->toBe($second->id)->and($service->is_active)->toBeFalse();
    });

    it('rejects duplicate service slugs but allows a service slug that matches a category slug', function () {
        $category = category(['name' => 'Data', 'slug' => 'data']);
        catalogService(['category_id' => $category->id, 'name' => 'Data', 'slug' => 'data']);
        $this->actingAs(catalogStaff(), 'admin');

        $this->post('/admin/services', ['category_id' => $category->id, 'name' => 'DATA'])->assertSessionHasErrors('name');
        $this->post('/admin/services', ['category_id' => $category->id, 'name' => 'Smile Data'])->assertSessionHasNoErrors();

        expect(Service::pluck('slug')->sort()->values()->all())->toBe(['data', 'smile-data']);
    });

    it('rejects a missing or invalid category', function (mixed $categoryId) {
        $this->actingAs(catalogStaff(), 'admin')->post('/admin/services', ['category_id' => $categoryId, 'name' => 'Orphan'])
            ->assertSessionHasErrors('category_id');

        expect(Service::count())->toBe(0);
    })->with([null, 999999, 'abc']);

    it('rejects moving a service to an invalid category', function () {
        $service = catalogService();

        $this->actingAs(catalogStaff(), 'admin')->put("/admin/services/{$service->id}", ['category_id' => 999999, 'name' => 'X'])
            ->assertSessionHasErrors('category_id');
        expect($service->fresh()->category_id)->not->toBe(999999);
    });

    it('lists, searches, filters by category and status, and paginates services', function () {
        $telecom = category(['name' => 'Telecom', 'slug' => 'telecom', 'sort_order' => 1]);
        $identity = category(['name' => 'Identity', 'slug' => 'identity', 'sort_order' => 2]);
        catalogService(['category_id' => $telecom->id, 'name' => 'Data', 'slug' => 'data', 'sort_order' => 1]);
        catalogService(['category_id' => $telecom->id, 'name' => 'Airtime', 'slug' => 'airtime', 'sort_order' => 2, 'is_active' => false]);
        catalogService(['category_id' => $identity->id, 'name' => 'NIN', 'slug' => 'nin', 'sort_order' => 3]);
        foreach (range(1, 15) as $i) {
            catalogService(['category_id' => $identity->id, 'name' => "Extra {$i}", 'slug' => "extra-{$i}", 'sort_order' => 100]);
        }
        $this->actingAs(catalogStaff(), 'admin');

        $this->get('/admin/services')->assertSeeInOrder(['Data', 'Airtime', 'NIN'])->assertSee('Showing 1–15 of 18');
        $this->get('/admin/services?page=2')->assertSee('Showing 16–18 of 18');
        $this->get("/admin/services?category={$telecom->id}")->assertSee('data-service="data"', false)->assertSee('data-service="airtime"', false)->assertDontSee('data-service="nin"', false);
        $this->get('/admin/services?q=airt')->assertSee('data-service="airtime"', false)->assertDontSee('data-service="data"', false);
        $this->get('/admin/services?status=disabled')->assertSee('data-service="airtime"', false)->assertDontSee('data-service="data"', false);
        $this->get('/admin/services?q=zzz')->assertSee('No services found');
        $this->get('/admin/services?category=999999')->assertSessionHasErrors('category');
    });

    it('lists seeded services grouped by category order', function () {
        $this->seed(ServiceCatalogSeeder::class);

        $this->actingAs(catalogStaff(), 'admin')->get('/admin/services')->assertSeeInOrder([
            'data-service="data"', 'data-service="airtime"', 'data-service="airtime-to-cash"', 'data-service="alpha-topup"', 'data-service="smile-data"',
            'data-service="cable-tv"', 'data-service="electricity"', 'data-service="bills-payment"',
            'data-service="exam-pin"', 'data-service="nin"', 'data-service="bvn"',
            'data-service="withdraw"', 'data-service="referral-and-commission"',
        ], false);
    });

    it('shows service details', function () {
        $service = catalogService(['name' => 'BVN', 'slug' => 'bvn', 'description' => 'Bank Verification Number.']);

        $this->actingAs(catalogStaff(), 'admin')->get("/admin/services/{$service->id}")->assertOk()
            ->assertSee('data-service-details', false)->assertSee('BVN')->assertSee('Bank Verification Number.')
            ->assertSee($service->category->name);
    });

    it('enables and disables a service', function () {
        $service = catalogService(['is_active' => false]);
        $this->actingAs(catalogStaff(), 'admin');

        $this->patch("/admin/services/{$service->id}/status", ['is_active' => 1]);
        expect($service->fresh()->is_active)->toBeTrue();

        $this->patch("/admin/services/{$service->id}/status", ['is_active' => 0]);
        expect($service->fresh()->is_active)->toBeFalse();
    });

    it('returns 404 for unknown services and non-numeric ids', function () {
        $this->actingAs(catalogStaff(), 'admin');

        $this->get('/admin/services/999999')->assertNotFound();
        $this->get('/admin/services/not-a-number')->assertNotFound();
    });
});

describe('availability', function () {
    it('treats a service as available only when it and its category are active', function () {
        $activeCategory = category();
        $disabledCategory = category(['is_active' => false]);
        $available = catalogService(['category_id' => $activeCategory->id]);
        $serviceOff = catalogService(['category_id' => $activeCategory->id, 'is_active' => false]);
        $categoryOff = catalogService(['category_id' => $disabledCategory->id]);

        expect($available->isAvailable())->toBeTrue()->and($available->statusLabel())->toBe('Active')
            ->and($serviceOff->isAvailable())->toBeFalse()->and($serviceOff->statusLabel())->toBe('Disabled')
            ->and($categoryOff->isAvailable())->toBeFalse()->and($categoryOff->statusLabel())->toBe('Active (category disabled)')
            ->and(Service::available()->pluck('id')->all())->toBe([$available->id]);
    });

    it('keeps services\' own status when their category is disabled and restores availability when re-enabled', function () {
        $category = category();
        $on = catalogService(['category_id' => $category->id, 'name' => 'On', 'slug' => 'on']);
        $off = catalogService(['category_id' => $category->id, 'name' => 'Off', 'slug' => 'off', 'is_active' => false]);
        $this->actingAs(catalogStaff(), 'admin');

        $this->patch("/admin/services/categories/{$category->id}/status", ['is_active' => 0]);
        expect($on->fresh()->is_active)->toBeTrue()->and($off->fresh()->is_active)->toBeFalse()
            ->and($on->fresh()->isAvailable())->toBeFalse();

        $this->get('/admin/services')->assertSee('data-status="Active (category disabled)"', false);
        $this->get("/admin/services/{$on->id}")->assertSee('its category is disabled');

        $this->patch("/admin/services/categories/{$category->id}/status", ['is_active' => 1]);
        expect($on->fresh()->isAvailable())->toBeTrue()->and($off->fresh()->isAvailable())->toBeFalse();
    });

    it('filters available services', function () {
        $category = category();
        catalogService(['category_id' => $category->id, 'name' => 'Live', 'slug' => 'live']);
        catalogService(['category_id' => category(['is_active' => false])->id, 'name' => 'Hidden By Category', 'slug' => 'hidden-by-category']);

        $this->actingAs(catalogStaff(), 'admin')->get('/admin/services?status=available')
            ->assertSee('data-service="live"', false)->assertDontSee('data-service="hidden-by-category"', false);
    });
});

describe('starting catalog', function () {
    it('seeds the 13 planned services in the approved categories, all disabled', function () {
        $this->seed(ServiceCatalogSeeder::class);

        $map = ServiceCategory::with('services')->get()
            ->mapWithKeys(fn ($c) => [$c->name => $c->services->sortBy('sort_order')->pluck('name')->values()->all()])->all();

        expect($map)->toBe([
            'Telecom' => ['Data', 'Airtime', 'Airtime to Cash', 'Alpha Topup', 'Smile Data'],
            'Bills & Utilities' => ['Cable TV', 'Electricity', 'Bills Payment'],
            'Education' => ['Exam Pin'],
            'Identity Verification' => ['NIN', 'BVN'],
            'Wallet & Earnings' => ['Withdraw', 'Referral & Commission'],
        ])
            ->and(Service::count())->toBe(13)
            ->and(Service::where('is_active', true)->count())->toBe(0)
            ->and(Service::available()->count())->toBe(0)
            ->and(ServiceCategory::where('is_active', false)->count())->toBe(0);
    });

    it('keeps NIN/BVN and Data/Smile Data as separate services, and data durations are not services', function () {
        $this->seed(ServiceCatalogSeeder::class);

        expect(Service::whereIn('slug', ['nin', 'bvn', 'data', 'smile-data'])->count())->toBe(4)
            ->and(Service::where('name', 'like', '%daily%')->orWhere('name', 'like', '%weekly%')->orWhere('name', 'like', '%monthly%')->count())->toBe(0);
    });

    it('is idempotent and never overwrites admin edits', function () {
        $this->seed(ServiceCatalogSeeder::class);
        $data = Service::firstWhere('slug', 'data');
        $data->forceFill(['name' => 'Mobile Data', 'description' => 'Edited', 'is_active' => true, 'sort_order' => 99])->save();
        ServiceCategory::firstWhere('slug', 'education')->forceFill(['is_active' => false, 'name' => 'Schools'])->save();
        Service::firstWhere('slug', 'bvn')->update(['category_id' => ServiceCategory::firstWhere('slug', 'telecom')->id]);
        Service::firstWhere('slug', 'withdraw')->delete();

        $this->seed(ServiceCatalogSeeder::class);
        $this->seed(ServiceCatalogSeeder::class);

        $data->refresh();
        expect(Service::count())->toBe(13)->and(ServiceCategory::count())->toBe(5)
            ->and($data->name)->toBe('Mobile Data')->and($data->description)->toBe('Edited')->and($data->is_active)->toBeTrue()->and($data->sort_order)->toBe(99)
            ->and(ServiceCategory::firstWhere('slug', 'education')->name)->toBe('Schools')
            ->and(ServiceCategory::firstWhere('slug', 'education')->is_active)->toBeFalse()
            ->and(Service::firstWhere('slug', 'bvn')->category->slug)->toBe('telecom')
            // Missing records are added back, disabled.
            ->and(Service::firstWhere('slug', 'withdraw')->is_active)->toBeFalse();
    });

    it('is part of the main database seeder', function () {
        $this->seed();

        expect(Service::count())->toBe(13);
    });
});

describe('scope', function () {
    it('adds no purchasing, pricing, plans, providers or customer-facing catalog pages', function () {
        expect(Schema::hasTable('plans'))->toBeFalse()->and(Schema::hasTable('products'))->toBeFalse()
            ->and(Schema::hasTable('providers'))->toBeFalse()->and(Schema::hasTable('orders'))->toBeFalse()
            ->and(Schema::hasTable('transactions'))->toBeFalse()->and(Schema::hasTable('wallets'))->toBeFalse()
            ->and(Schema::hasColumns('services', ['price']))->toBeFalse()
            ->and(Schema::hasColumns('services', ['provider_id']))->toBeFalse();

        $customerCatalogRoutes = collect(Route::getRoutes())->filter(fn ($r) => ! str_starts_with($r->uri(), 'admin')
            && preg_match('/(service|categor|plan|purchase|buy|order|wallet)/i', $r->uri()));
        expect($customerCatalogRoutes->map->uri()->values()->all())->toBe([]);
    });

    it('shows no prices, balances or purchase actions in the admin catalog pages', function () {
        $this->seed(ServiceCatalogSeeder::class);
        $service = Service::firstWhere('slug', 'data');
        $this->actingAs(catalogStaff(), 'admin');

        foreach (['/admin/services', "/admin/services/{$service->id}", '/admin/services/categories'] as $url) {
            $html = mb_strtolower($this->get($url)->getContent());
            foreach (['₦', 'price', 'balance', 'buy now', 'purchase now', 'checkout', 'provider:'] as $word) {
                expect($html)->not->toContain($word, "{$url} contains {$word}");
            }
        }
    });

    it('keeps the customer dashboard free of catalog services', function () {
        $this->seed(ServiceCatalogSeeder::class);
        Service::query()->update(['is_active' => true]);

        $html = $this->actingAs(User::factory()->create(), 'web')->get('/dashboard')->assertOk()->getContent();
        expect($html)->not->toContain('Smile Data')->not->toContain('Airtime')->not->toContain('Telecom');
    });
});
