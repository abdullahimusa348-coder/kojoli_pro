<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseResult;
use App\Models\Service;
use App\Models\SystemUser;
use App\Support\Enums\UserType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ComponentAttributeBag;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP4 page guard for Exam PIN: the data of every customer and staff
 * view is recorded while the pages render. Only the signed-in owner's
 * successful result page receives (and shows) the delivered values, such as
 * the PIN and serial; no other page, customer or staff, receives a value or a
 * stored result model, and staff views never receive result fields. Result
 * values are neutral fixtures generated when the tests run (D1).
 */

beforeEach(function () {
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn', 'exam-pin'];
    Http::preventStrayRequests();
});

function xpgPlan(): Plan
{
    $service = Service::where('slug', 'exam-pin')->first() ?? Service::factory()->create(['name' => 'Exam PIN', 'slug' => 'exam-pin']);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Fixture exam', 'code' => 'exam-pin-test-'.Str::lower(Str::random(6)),
        'network' => null]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Fixture PIN', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);

    return $plan->fresh();
}

/** Records every view rendered from now on: its name, its data keys, every string reachable from its data, and how many stored results it holds. */
function xpgSpy(): ArrayObject
{
    $rendered = new ArrayObject;
    View::composer('*', function ($view) use ($rendered) {
        $found = ['strings' => [], 'results' => 0];
        xpgWalk($view->getData(), $found, new SplObjectStorage, 0);
        $rendered->append(['view' => $view->name(), 'keys' => array_keys($view->getData())] + $found);
    });

    return $rendered;
}

/** Collects strings from view data: scalars, arrays, models (attributes and loaded relations), collections, paginators, enums, errors and plain objects. */
function xpgWalk(mixed $value, array &$found, SplObjectStorage $seen, int $depth): void
{
    if ($depth > 12 || $value === null || is_bool($value)) {
        return;
    }
    if (is_scalar($value)) {
        $found['strings'][] = (string) $value;

        return;
    }
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $found['strings'][] = (string) $key;
            xpgWalk($item, $found, $seen, $depth + 1);
        }

        return;
    }
    if (! is_object($value) || $seen->contains($value) || $value instanceof Closure || $value instanceof DateTimeInterface
        || str_starts_with($value::class, 'Illuminate\\Foundation\\') || str_starts_with($value::class, 'Illuminate\\View\\Factory')
        || str_starts_with($value::class, 'Illuminate\\Container\\')) {
        return;
    }
    $seen->attach($value);
    if ($value instanceof PurchaseResult) {
        $found['results']++;
    }
    $next = match (true) {
        $value instanceof EloquentModel => [$value->getAttributes(), $value->getRelations()],
        $value instanceof AbstractPaginator => $value->items(),
        $value instanceof Collection => $value->all(),
        $value instanceof UnitEnum => [$value instanceof BackedEnum ? $value->value : $value->name],
        $value instanceof ViewErrorBag => array_map(fn (MessageBag $bag) => $bag->getMessages(), $value->getBags()),
        $value instanceof MessageBag => $value->getMessages(),
        $value instanceof ComponentAttributeBag => $value->getAttributes(),
        $value instanceof Htmlable => [$value->toHtml()],
        default => get_object_vars($value),
    };
    xpgWalk($next, $found, $seen, $depth + 1);
}

it('lets only the owner\'s successful result page receive and show the delivered values, and no staff page receive result fields', function () {
    $plan = xpgPlan();
    $owner = puxCustomer(300_000);
    $other = puxCustomer(300_000);
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');
    $fields = FakeProvider::fixtureFields(3);
    $views = xpgSpy();
    $pages = [];
    $visit = function (string $page, Closure $request) use ($views, &$pages) {
        $views->exchangeArray([]);
        $response = $request();
        $pages[$page] = ['response' => $response, 'views' => $views->getArrayCopy()];

        return $response;
    };

    $this->actingAs($owner);
    $visit('buy', fn () => $this->get('/buy'));
    $visit('buy Exam PIN', fn () => $this->get('/buy/exam-pin'));
    $visit('refused plan', fn () => $this->from('/buy/exam-pin')->post('/buy/exam-pin/confirm', ['plan' => 0]));
    $confirm = $visit('confirmation', fn () => $this->post('/buy/exam-pin/confirm', ['plan' => $plan->id]));
    FakeProvider::$purchaseScript = ['succeeded'];
    FakeProvider::$resultScript = [$fields];
    $visit('payment', fn () => $this->post('/buy/exam-pin', ['confirmation' => $confirm->viewData('confirmation')]));
    $purchase = Purchase::sole();
    FakeProvider::$purchaseScript = ['timeout'];
    $this->post('/buy/exam-pin', ['confirmation' => $this->post('/buy/exam-pin/confirm', ['plan' => $plan->id])->viewData('confirmation')]);
    $pending = Purchase::where('id', '!=', $purchase->id)->sole();
    $visit('result (owner)', fn () => $this->get(route('purchases.show', $purchase->reference)));
    $visit('pending result (owner)', fn () => $this->get(route('purchases.show', $pending->reference)));
    $visit('history (owner)', fn () => $this->get('/purchases'));
    $visit('dashboard (owner)', fn () => $this->get('/dashboard'));
    $visit('wallet (owner)', fn () => $this->get('/wallet'));
    $this->actingAs($other);
    $visit('result (another customer)', fn () => $this->get(route('purchases.show', $purchase->reference)));
    $visit('history (another customer)', fn () => $this->get('/purchases'));
    $visit('dashboard (another customer)', fn () => $this->get('/dashboard'));
    $this->actingAs($staff, 'admin');
    $visit('staff: purchases', fn () => $this->get('/admin/purchases'));
    $visit('staff: purchase', fn () => $this->get("/admin/purchases/{$purchase->id}"));
    $visit('staff: pending purchase', fn () => $this->get("/admin/purchases/{$pending->id}"));
    $visit('staff: search by reference', fn () => $this->get('/admin/purchases?q='.$purchase->reference));
    $visit('staff: customer', fn () => $this->get("/admin/users/{$owner->id}"));
    $visit('staff: wallet', fn () => $this->get("/admin/wallet/{$owner->id}"));
    $visit('staff: transactions', fn () => $this->get('/admin/transactions'));
    $visit('staff: transaction', fn () => $this->get('/admin/transactions/'.$purchase->debit_transaction_id));
    $visit('staff: dashboard', fn () => $this->get('/admin'));

    $values = array_column($fields->all(), 'value');
    expect($pages['result (owner)']['response']->status())->toBe(200)
        ->and($pages['result (another customer)']['response']->status())->toBe(404)
        ->and(collect($pages)->filter(fn ($page) => $page['response']->status() >= 500)->keys()->all())->toBe([]);
    foreach ($pages as $page => ['response' => $response, 'views' => $rendered]) {
        $html = $response->getContent();
        $data = implode("\n", array_merge([], ...array_column($rendered, 'strings')));
        $ownResult = $page === 'result (owner)';
        foreach ($values as $value) {
            expect(str_contains($data, $value))->toBe($ownResult, "{$page}: view data and a result value")
                ->and(str_contains($html, $value))->toBe($ownResult, "{$page}: HTML and a result value")
                ->and(str_contains($response->headers->get('Location') ?? '', $value))->toBeFalse("{$page}: redirect");
        }
        foreach ($rendered as $view) {
            expect($view['results'])->toBe(0, "{$page}: {$view['view']} received a stored result");
            if (str_starts_with($page, 'staff: ')) {
                expect(array_values(array_intersect($view['keys'], ['resultFields', 'fields'])))->toBe([], "{$page}: {$view['view']} received result fields");
            }
        }
    }
    expect(collect($pages['result (owner)']['views'])->pluck('view'))->toContain('user.purchases.show')->toContain('partials.purchases.result-fields')
        ->and(collect($pages['pending result (owner)']['views'])->pluck('view'))->not->toContain('partials.purchases.result-fields')
        ->and($pages['result (owner)']['response']->headers->get('Cache-Control'))->toBe('no-store, private')
        ->and($pages['confirmation']['response']->headers->get('Cache-Control'))->toBe('no-store, private');
});
