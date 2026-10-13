<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Service;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Support\Catalog\CatalogSlug;
use App\Support\Catalog\DefaultCatalog;
use App\Support\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ServiceCatalogSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP4: the existing Phase 5 catalog service (slug exam-pin) is
 * displayed as "Exam PIN". The catalog definition names it so for new
 * installs; its slug and identity never change. The catalog seeder never
 * renames an existing row (an install seeded before CP4 is renamed in Admin →
 * Services, which keeps the slug). Every page that shows the catalog service
 * name, or a purchase's snapshot of it, shows "Exam PIN".
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    puxDrivers();
    FakeProvider::$services = ['exam-pin'];
    Http::preventStrayRequests();
});

function xsnStaff(): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');

    return $staff;
}

it('defines and seeds the existing Exam PIN service as "Exam PIN", with the slug exam-pin, once', function () {
    $definition = collect(DefaultCatalog::categories())->flatMap(fn (array $category) => $category['services'])->firstWhere('name', 'Exam PIN');

    $this->seed(ServiceCatalogSeeder::class);
    $this->seed(ServiceCatalogSeeder::class);

    $service = Service::where('slug', 'exam-pin')->sole();
    expect($definition)->not->toBeNull()
        ->and(CatalogSlug::from('Exam PIN'))->toBe('exam-pin')
        ->and($service->name)->toBe('Exam PIN')
        ->and($service->category->name)->toBe('Education')
        ->and($service->is_active)->toBeFalse()
        ->and(Service::where('name', 'like', 'Exam%')->count())->toBe(1);
});

it('never renames an existing row from the seeder: staff rename it in place, keeping its id and slug', function () {
    $this->seed(ServiceCatalogSeeder::class);
    $service = Service::where('slug', 'exam-pin')->sole();
    $service->forceFill(['name' => 'Exam Pin'])->save(); // as an install seeded before CP4 holds it

    $this->seed(ServiceCatalogSeeder::class);
    expect($service->fresh()->name)->toBe('Exam Pin');

    $this->actingAs(xsnStaff(), 'admin')->put("/admin/services/{$service->id}", ['category_id' => $service->category_id, 'name' => 'Exam PIN',
        'description' => $service->description, 'icon' => $service->icon?->value, 'sort_order' => $service->sort_order])->assertSessionHasNoErrors();

    $renamed = $service->fresh();
    expect($renamed->name)->toBe('Exam PIN')
        ->and($renamed->id)->toBe($service->id)
        ->and($renamed->slug)->toBe('exam-pin')
        ->and($renamed->category_id)->toBe($service->category_id)
        ->and(Service::where('slug', 'exam-pin')->count())->toBe(1);
});

it('shows "Exam PIN" wherever the catalog service name is shown, to customers and staff, and never "Exam Pin"', function () {
    $this->seed(ServiceCatalogSeeder::class);
    $service = Service::where('slug', 'exam-pin')->sole();
    $service->forceFill(['is_active' => true])->save();
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Fixture exam', 'code' => 'exam-pin-test-'.Str::lower(Str::random(6)),
        'network' => null]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Fixture PIN', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);
    FakeProvider::$purchaseScript = ['timeout'];

    $this->actingAs(puxCustomer(100_000));
    $pages = ['buy' => $this->get('/buy')->assertOk()->getContent(), 'buy page' => $this->get('/buy/exam-pin')->assertOk()->getContent()];
    $confirm = $this->post('/buy/exam-pin/confirm', ['plan' => $plan->id])->assertOk();
    $pages['confirmation'] = $confirm->getContent();
    $this->post('/buy/exam-pin', ['confirmation' => $confirm->viewData('confirmation')])->assertRedirect();
    $purchase = Purchase::sole();
    $pages['result'] = $this->get(route('purchases.show', $purchase->reference))->assertOk()->getContent();
    $pages['history'] = $this->get('/purchases')->assertOk()->getContent();
    $pages['dashboard'] = $this->get('/dashboard')->assertOk()->getContent();
    $pages['wallet'] = $this->get('/wallet')->assertOk()->getContent();
    $this->actingAs(xsnStaff(), 'admin');
    $pages['staff: purchases'] = $this->get('/admin/purchases')->assertOk()->getContent();
    $pages['staff: purchase'] = $this->get("/admin/purchases/{$purchase->id}")->assertOk()->getContent();
    $pages['staff: services'] = $this->get('/admin/services')->assertOk()->getContent();
    $pages['staff: service'] = $this->get("/admin/services/{$service->id}")->assertOk()->getContent();
    $pages['staff: transaction'] = $this->get('/admin/transactions/'.$purchase->debit_transaction_id)->assertOk()->getContent();

    expect($purchase->service_name)->toBe('Exam PIN')
        ->and(Transaction::findOrFail($purchase->debit_transaction_id)->description)->toBe('Exam PIN: Fixture PIN')
        ->and($pages['buy'])->toContain('<h2 class="text-base font-semibold text-navy-900">Exam PIN</h2>')
        ->and($pages['buy page'])->toContain('Buy Exam PIN')
        ->and($pages['confirmation'])->toContain('<dt class="text-navy-600">Service</dt><dd class="mt-0.5 font-medium text-navy-900">Exam PIN</dd>')
        ->and($pages['result'])->toContain('Exam PIN · Fixture exam')
        ->and($pages['history'])->toContain('Exam PIN · Fixture PIN')
        ->and($pages['dashboard'])->toContain('Exam PIN · Fixture PIN')
        ->and($pages['wallet'])->toContain('Exam PIN: Fixture PIN')
        ->and($pages['staff: purchases'])->toContain('Exam PIN · Fixture exam · Fixture PIN')->toContain('>Exam PIN</option>')
        ->and($pages['staff: purchase'])->toContain('Exam PIN · Fixture exam · Fixture PIN')
        ->and($pages['staff: services'])->toContain('Exam PIN')->toContain('data-service="exam-pin"')
        ->and($pages['staff: service'])->toContain('Exam PIN')
        ->and($pages['staff: transaction'])->toContain('Exam PIN: Fixture PIN');
    foreach ($pages as $page => $html) {
        expect($html)->not->toContain('Exam Pin', $page);
    }
    expect($service->fresh()->slug)->toBe('exam-pin')
        ->and(route('buy.exam-pin'))->toBe(url('/buy/exam-pin'));
});
