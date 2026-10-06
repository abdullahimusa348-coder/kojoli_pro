<?php

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseIdentityRecipient;
use App\Models\PurchaseResult;
use App\Models\Service;
use App\Models\SystemUser;
use App\Support\Enums\UserType;
use App\Support\Purchases\RecipientType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ComponentAttributeBag;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 11 CP3 page guards (replacing the CP1/CP2 code scans of pages):
 * - only masked NIN/BVN values reach the views, except the confirmation page,
 *   which shows the number once before payment;
 * - only the signed-in owner's result page receives the decrypted result;
 * - staff pages never receive a result value or the number;
 * - no page other than the confirmation page renders the number.
 * The views' data is recorded while every customer and staff page is
 * rendered; the code is checked so that only the intended places can read
 * results or print recipients. Numbers and result values are neutral
 * fixtures generated when the tests run (D1).
 */

beforeEach(function () {
    puxDrivers();
    FakeProvider::$services = ['data', 'airtime', 'nin', 'bvn'];
    Http::preventStrayRequests();
});

function ipgNumber(): string
{
    return (string) random_int(10_000_000_000, 99_999_999_999);
}

function ipgPlan(RecipientType $type): Plan
{
    $service = Service::where('slug', $type->value)->first() ?? Service::factory()->create(['name' => $type->label(), 'slug' => $type->value]);
    $product = Product::factory()->create(['service_id' => $service->id, 'name' => 'Test product', 'code' => $type->value.'-test-'.Str::lower(Str::random(6))]);
    $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Test plan', 'code' => $product->code.'-p', 'amount_type' => 'fixed']);
    PlanPrice::factory()->create(['plan_id' => $plan->id, 'user_type' => UserType::Subscriber, 'price_kobo' => 15_000]);
    puxRoute($plan, 1, ['cost_type' => 'fixed', 'cost_kobo' => 10_000]);

    return $plan->fresh();
}

/**
 * Records every view rendered from now on: its name, its data keys, every
 * string reachable from its data, the attribute names of every identity
 * recipient in it, and how many stored results it holds.
 */
function ipgSpy(): ArrayObject
{
    $rendered = new ArrayObject;
    View::composer('*', function ($view) use ($rendered) {
        $found = ['strings' => [], 'identities' => [], 'results' => 0];
        ipgWalk($view->getData(), $found, new SplObjectStorage, 0);
        $rendered->append(['view' => $view->name(), 'keys' => array_keys($view->getData())] + $found);
    });

    return $rendered;
}

/** Collects strings from view data: scalars, arrays, models (attributes and loaded relations), collections, paginators, enums, errors and plain objects. */
function ipgWalk(mixed $value, array &$found, SplObjectStorage $seen, int $depth): void
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
            ipgWalk($item, $found, $seen, $depth + 1);
        }

        return;
    }
    if (! is_object($value) || $seen->contains($value) || $value instanceof Closure || $value instanceof DateTimeInterface
        || str_starts_with($value::class, 'Illuminate\\Foundation\\') || str_starts_with($value::class, 'Illuminate\\View\\Factory')
        || str_starts_with($value::class, 'Illuminate\\Container\\')) {
        return;
    }
    $seen->attach($value);
    if ($value instanceof PurchaseIdentityRecipient) {
        $found['identities'][] = array_keys($value->getAttributes());
    }
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
    ipgWalk($next, $found, $seen, $depth + 1);
}

it('lets only the confirmation page receive the number, only the owner\'s result page the result, and every other page masked numbers only', function () {
    $nin = ipgPlan(RecipientType::Nin);
    $bvn = ipgPlan(RecipientType::Bvn);
    $owner = puxCustomer(300_000);
    $other = puxCustomer(300_000);
    (new RolesAndPermissionsSeeder)->run();
    $staff = SystemUser::factory()->create();
    $staff->assignRole('super-admin');
    [$number, $bvnNumber] = [ipgNumber(), ipgNumber()];
    $fields = FakeProvider::fixtureFields(3);
    $views = ipgSpy();
    $pages = [];
    $visit = function (string $page, Closure $request) use ($views, &$pages) {
        $views->exchangeArray([]);
        $response = $request();
        $pages[$page] = ['response' => $response, 'views' => $views->getArrayCopy()];

        return $response;
    };

    $this->actingAs($owner);
    $visit('buy', fn () => $this->get('/buy'));
    $visit('buy NIN', fn () => $this->get('/buy/nin'));
    $visit('refused entry', fn () => $this->from('/buy/nin')->post('/buy/nin/confirm', ['plan' => 0, 'identity_number' => $number]));
    $visit('buy NIN after the refusal', fn () => $this->get('/buy/nin'));
    $confirm = $visit('confirmation', fn () => $this->post('/buy/nin/confirm', ['plan' => $nin->id, 'identity_number' => $number]));
    $visit('confirmation without consent', fn () => $this->post('/buy/nin', ['confirmation' => $confirm->viewData('confirmation')]));
    FakeProvider::$purchaseScript = ['succeeded'];
    FakeProvider::$resultScript = [$fields];
    $visit('payment', fn () => $this->post('/buy/nin', ['confirmation' => $confirm->viewData('confirmation'), 'consent' => '1']));
    $purchase = Purchase::sole();
    FakeProvider::$purchaseScript = ['timeout'];
    $bvnConfirm = $this->post('/buy/bvn/confirm', ['plan' => $bvn->id, 'identity_number' => $bvnNumber]);
    $this->post('/buy/bvn', ['confirmation' => $bvnConfirm->viewData('confirmation'), 'consent' => '1']);
    $pending = Purchase::where('recipient_type', 'bvn')->sole();
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
    $visit('staff: search', fn () => $this->post('/admin/purchases/identity-search', ['identity_type' => 'nin', 'identity_number' => $number]));
    $visit('staff: search results', fn () => $this->get('/admin/purchases?identity=1'));
    $visit('staff: refused search', fn () => $this->post('/admin/purchases/identity-search', ['identity_type' => 'bvn', 'identity_number' => $bvnNumber.'0']));
    $visit('staff: purchases after the refusal', fn () => $this->get('/admin/purchases'));
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
        $confirmation = in_array($page, ['confirmation', 'confirmation without consent'], true);
        $ownResult = $page === 'result (owner)';
        expect(str_contains($data, $number))->toBe($confirmation, "{$page}: view data and the number")
            ->and(str_contains($html, $number))->toBe($confirmation, "{$page}: HTML and the number")
            ->and(str_contains($html.$data, $bvnNumber))->toBeFalse("{$page}: the BVN")
            ->and(str_contains($response->headers->get('Location') ?? '', $number))->toBeFalse("{$page}: redirect");
        foreach ($values as $value) {
            expect(str_contains($data, $value))->toBe($ownResult, "{$page}: view data and a result value")
                ->and(str_contains($html, $value))->toBe($ownResult, "{$page}: HTML and a result value");
        }
        foreach ($rendered as $view) {
            expect($view['results'])->toBe(0, "{$page}: {$view['view']} received a stored result");
            foreach ($view['identities'] as $attributes) {
                expect($attributes)->toEqualCanonicalizing(['id', 'purchase_id', 'masked_value'], "{$page}: {$view['view']} received more than the masked number");
            }
            if (str_starts_with($page, 'staff: ')) {
                expect(array_values(array_intersect($view['keys'], ['resultFields', 'fields'])))->toBe([], "{$page}: {$view['view']} received result fields");
            }
        }
    }
    expect(str_contains($pages['staff: purchase']['response']->getContent(), RecipientType::Nin->mask($number)))->toBeTrue()
        ->and(str_contains($pages['history (owner)']['response']->getContent(), RecipientType::Bvn->mask($bvnNumber)))->toBeTrue()
        ->and(collect($pages['result (owner)']['views'])->pluck('view'))->toContain('user.purchases.show')->toContain('partials.purchases.result-fields')
        ->and(collect($pages['pending result (owner)']['views'])->pluck('view'))->not->toContain('partials.purchases.result-fields');
});

it('reads results and numbers only where intended, and prints recipients only through the masking helper', function () {
    $sources = collect([app_path(), resource_path(), base_path('routes'), config_path()])
        ->flatMap(fn (string $dir) => File::allFiles($dir))
        ->mapWithKeys(fn ($file) => [Str::after($file->getPathname(), base_path().'/') => $file->getContents()]);
    $using = fn (string $pattern) => $sources->filter(fn (string $code) => preg_match($pattern, $code) === 1)->keys()->sort()->values()->all();
    $views = $sources->filter(fn ($code, $path) => str_starts_with($path, 'resources/views/'));
    $staff = $sources->filter(fn ($code, $path) => str_starts_with($path, 'resources/views/admin/') || str_starts_with($path, 'app/Http/Controllers/Admin/'));

    // Decrypted results: the result model, read only by the owner's result page (customer PurchaseController, after its owner lookup).
    expect($using('/->fields\(\)/'))->toBe(['app/Http/Controllers/User/PurchaseController.php', 'app/Models/PurchaseResult.php'])
        ->and($using('/resultFields/'))->toBe(['app/Http/Controllers/User/PurchaseController.php', 'resources/views/user/purchases/show.blade.php'])
        ->and($using('/partials\.purchases\.result-fields/'))->toBe(['resources/views/user/purchases/show.blade.php'])
        // The number: decrypted only by the identity model and the engine; given to a view only by the confirmation page.
        ->and($using('/->number\(/'))->toBe(['app/Models/PurchaseIdentityRecipient.php', 'app/Services/Purchases/PurchaseService.php'])
        ->and($using("/'number' =>/"))->toBe(['app/Http/Controllers/User/IdentityBuyController.php'])
        ->and($views->filter(fn (string $code) => str_contains($code, '$number'))->keys()->all())->toBe(['resources/views/user/buy/identity/confirm.blade.php'])
        // Recipients are printed only through displayRecipient() (the masked number of a NIN/BVN purchase).
        ->and($views->filter(fn (string $code) => preg_match('/->recipient\b/', $code))->keys()->all())->toBe([])
        ->and($views->filter(fn (string $code) => preg_match('/identityRecipient|identity_recipient|masked_value|encrypted_value/', $code))->keys()->all())->toBe([])
        // Staff code never touches result values or the number.
        ->and($staff->filter(fn (string $code) => preg_match('/->fields\(\)|resultFields|result-fields|encrypted_fields|encrypted_value|->number\(/', $code))->keys()->all())
        ->toBe([]);
});
