<?php

use App\Models\Plan;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Purchases/helpers.php';

/*
 * Phase 10 Step 3, CP3: what customers see of their own purchases outside the
 * Buy pages: the dashboard Buy shortcut (only while something can be bought),
 * recent purchases with the in-progress note, the pending result page that
 * refreshes itself for its first ten minutes, and "View purchase" links in
 * the wallet. Only the customer's own purchases and customer-facing details,
 * with a fixed number of queries. Test-only FakeProvider.
 */

beforeEach(function () {
    puxDrivers();
    Http::preventStrayRequests();
});

function cpvAt(string $utc): void
{
    Carbon::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
}

/** An available Data plan with an executable FakeProvider route. */
function cpvPlan(): Plan
{
    $plan = puxPlan('data', 50_000);
    puxRoute($plan);

    return $plan->fresh();
}

/** Buys $plan for $user; the provider answers $script ("timeout" leaves it pending). */
function cpvBuy(User $user, Plan $plan, string $script = 'succeeded', string $phone = '08012345678'): Purchase
{
    FakeProvider::$purchaseScript = [$script];

    return puxService()->purchase($user, $plan, $phone, null, (string) Str::uuid());
}

/** The HTML of one list row, found by its data attribute. */
function cpvRow(string $html, string $attribute, string $value): string
{
    preg_match('/<li[^>]*'.$attribute.'="'.preg_quote($value, '/').'".*?<\/li>/s', $html, $match);

    return $match[0] ?? '';
}

/** @return array{0: int, 1: int} all queries and purchases-table queries of one page view */
function cpvQueries($test, User $user, string $url): array
{
    $test->actingAs($user, 'web');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $test->get($url)->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    return [$queries->count(), $queries->filter(fn ($q) => preg_match('/from\s+["`]?purchases["`]?/i', $q))->count()];
}

describe('dashboard Buy shortcut', function () {
    it('is not offered with no provider adapter installed (production today)', function () {
        config(['providers.drivers' => []]);
        cpvPlan();

        $html = $this->actingAs(puxCustomer())->get('/dashboard')->assertOk()->getContent();
        expect($html)->not->toContain('data-shortcut="buy"')->and(substr_count($html, 'data-shortcut="'))->toBe(2);
    });

    it('is not offered when no plan can be bought', function () {
        $plan = cpvPlan();
        $plan->forceFill(['is_active' => false])->save();

        $this->actingAs(puxCustomer())->get('/dashboard')->assertOk()->assertDontSee('data-shortcut="buy"', false);
    });

    it('is offered first, linking to Buy, once a plan can be bought', function () {
        cpvPlan();
        $this->actingAs(puxCustomer());

        $this->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['data-shortcut="buy"', 'data-shortcut="account"', 'data-shortcut="security"'], false)
            ->assertSee('href="'.route('buy').'" data-shortcut="buy"', false)
            ->assertSee('Buy data or airtime with your wallet balance.');
        $this->get(route('buy'))->assertOk()->assertSee('Buy Data');
    });
});

describe('dashboard recent purchases', function () {
    it('is hidden until the customer has a purchase', function () {
        cpvPlan();

        $this->actingAs(puxCustomer())->get('/dashboard')->assertOk()
            ->assertDontSee('data-recent-purchases', false)->assertDontSee('Recent purchases')->assertDontSee('data-purchases-in-progress', false);
    });

    it('shows only the customer’s own latest five purchases, newest first, each linking to its result', function () {
        $plan = cpvPlan();
        $ada = puxCustomer(5_000_000);
        $bola = puxCustomer(5_000_000);
        $adas = collect(range(1, 7))->map(fn ($i) => cpvBuy($ada, $plan, 'succeeded', '0801000000'.$i));
        $bolas = collect(range(1, 2))->map(fn ($i) => cpvBuy($bola, $plan, 'succeeded', '0803000000'.$i)); // newer than all of Ada's

        $html = $this->actingAs($ada)->get('/dashboard')->assertOk()->assertSee('Recent purchases')->getContent();

        $newest = $adas->reverse()->take(5)->values();
        preg_match_all('/data-recent-purchase="([^"]+)"/', $html, $rows);
        expect($rows[1])->toBe($newest->pluck('reference')->all());
        foreach ($newest as $purchase) {
            expect(cpvRow($html, 'data-recent-purchase', $purchase->reference))
                ->toContain('href="'.route('purchases.show', $purchase->reference).'"')->toContain($purchase->recipient)->toContain('₦500.00');
        }
        foreach ([...$adas->take(2), ...$bolas] as $purchase) {
            expect($html)->not->toContain($purchase->reference)->not->toContain($purchase->recipient);
        }
        preg_match('/<a href="([^"]+)"[^>]*data-view-all-purchases/', $html, $viewAll);
        expect($viewAll[1] ?? null)->toBe(route('purchases'));
    });

    it('shows customer status labels and no provider, cost or internal details', function () {
        $plan = cpvPlan();
        $user = puxCustomer(1_000_000);
        cpvAt('2026-10-03 08:00:00');
        $review = cpvBuy($user, $plan, 'timeout');
        cpvAt('2026-10-04 09:00:00');
        FakeProvider::$queryScript = ['unknown'];
        expect(puxService()->recheck($review->fresh(), PurchaseSource::Reconcile)->status)->toBe(PurchaseStatus::Review);
        $purchases = ['successful' => cpvBuy($user, $plan, 'succeeded'), 'failed' => cpvBuy($user, $plan, 'failed_definite'),
            'pending' => cpvBuy($user, $plan, 'timeout'), 'review' => $review];

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        foreach (['successful' => 'Successful', 'failed' => 'Failed', 'pending' => 'Pending', 'review' => 'Under review'] as $status => $label) {
            expect(cpvRow($html, 'data-recent-purchase', $purchases[$status]->reference))
                ->toMatch('/data-purchase-status="'.$status.'">'.$label.'<\/span>/');
        }
        $provider = $plan->providerRoutes()->first()->provider;
        foreach ([$provider->name, $provider->code, 'Needs review', 'declined', 'Every provider', 'No definite provider outcome', 'FP-', 'PRA-',
            '₦450.00', 'CODE1', PUX_KEY] as $needle) {
            expect(str_contains($html, $needle))->toBeFalse("found {$needle}");
        }
    });

    it('notes how many of the customer’s own purchases are still being confirmed (pending or under review)', function () {
        $plan = cpvPlan();
        $ada = puxCustomer(5_000_000);
        cpvAt('2026-10-03 08:00:00');
        $review = cpvBuy($ada, $plan, 'timeout');
        cpvAt('2026-10-04 09:00:00');
        FakeProvider::$queryScript = ['unknown'];
        puxService()->recheck($review->fresh(), PurchaseSource::Reconcile);
        cpvBuy($ada, $plan, 'timeout');
        foreach (range(1, 5) as $i) {
            cpvBuy($ada, $plan, 'succeeded'); // the two still being confirmed are no longer among the latest five
        }
        $bola = puxCustomer(5_000_000);
        cpvBuy($bola, $plan, 'timeout');
        $carol = puxCustomer(5_000_000);
        cpvBuy($carol, $plan, 'succeeded');
        cpvBuy($carol, $plan, 'failed_definite');

        $this->actingAs($ada)->get('/dashboard')->assertSee('data-purchases-in-progress="2"', false)
            ->assertSee('2 purchases are still being confirmed. There is no need to buy again.');
        $this->actingAs($bola)->get('/dashboard')->assertSee('data-purchases-in-progress="1"', false)
            ->assertSee('1 purchase is still being confirmed. There is no need to buy again.');
        $this->actingAs($carol)->get('/dashboard')->assertSee('data-recent-purchases', false)
            ->assertDontSee('data-purchases-in-progress', false)->assertDontSee('still being confirmed');
    });

    it('reads the dashboard with a fixed number of queries, however many purchases there are', function () {
        $plan = cpvPlan();
        $few = puxCustomer(5_000_000);
        cpvBuy($few, $plan, 'timeout');
        $many = puxCustomer(5_000_000);
        foreach (['succeeded', 'timeout', 'failed_definite', 'succeeded', 'timeout', 'succeeded', 'succeeded'] as $script) {
            cpvBuy($many, $plan, $script);
        }
        cpvQueries($this, $many, '/dashboard'); // warm the settings and permission caches

        [$fewAll, $fewPurchases] = cpvQueries($this, $few, '/dashboard');
        [$manyAll, $manyPurchases] = cpvQueries($this, $many, '/dashboard');

        // Latest five, the in-progress count and the navigation's "My purchases" check.
        expect($manyAll)->toBe($fewAll)->and($manyPurchases)->toBe($fewPurchases)->toBe(3);
    });
});

describe('pending result page', function () {
    it('refreshes itself every 30 seconds for the first ten minutes after the purchase, keeping manual Refresh', function (string $now, bool $refreshes) {
        cpvAt('2026-10-04 09:00:00');
        $user = puxCustomer();
        $purchase = cpvBuy($user, cpvPlan(), 'timeout');
        cpvAt($now);

        $response = $this->actingAs($user)->get(route('purchases.show', $purchase->reference))->assertOk()->assertSee('Purchase pending')
            ->assertSee('href="'.route('purchases.show', $purchase->reference).'"', false)->assertSee('>Refresh</a>', false);

        if ($refreshes) {
            $response->assertSee('data-auto-refresh="30"', false)->assertSee('This page checks for updates every 30 seconds.')
                ->assertSee('x-init="timer = setTimeout(() => window.location.reload(), 30000)"', false)
                ->assertSee('data-auto-refresh-stop>Stop</button>', false)->assertSee('x-cloak', false);
        } else {
            $response->assertDontSee('data-auto-refresh', false)->assertDontSee('checks for updates')->assertDontSee('window.location.reload', false);
        }
    })->with([
        'just made' => ['2026-10-04 09:00:00', true],
        'after five minutes' => ['2026-10-04 09:05:00', true],
        'one second before ten minutes' => ['2026-10-04 09:09:59', true],
        'at exactly ten minutes' => ['2026-10-04 09:10:00', false],
        'one second after ten minutes' => ['2026-10-04 09:10:01', false],
        'hours later' => ['2026-10-04 15:00:00', false],
    ]);

    it('never refreshes itself under review or once the purchase is final', function (Closure $make, string $status, bool $manualRefresh) {
        cpvAt('2026-10-04 09:00:00');
        $user = puxCustomer();
        $purchase = $make($user);
        cpvAt('2026-10-04 09:03:00');

        $html = $this->actingAs($user)->get(route('purchases.show', $purchase->reference))->assertOk()->getContent();
        expect($html)->toContain('data-purchase-result="'.$status.'"')->not->toContain('data-auto-refresh')->not->toContain('checks for updates')
            ->and(str_contains($html, '>Refresh</a>'))->toBe($manualRefresh);
    })->with([
        'successful' => [fn (User $user) => cpvBuy($user, cpvPlan(), 'succeeded'), 'successful', false],
        'failed and refunded' => [fn (User $user) => cpvBuy($user, cpvPlan(), 'failed_definite'), 'failed', false],
        'under review within the first ten minutes' => [function (User $user) {
            config(['purchases.review_after_hours' => 0]); // reach review early to test the status rule
            $purchase = cpvBuy($user, cpvPlan(), 'timeout');
            cpvAt('2026-10-04 09:02:30');
            FakeProvider::$queryScript = ['unknown'];

            return puxService()->recheck($purchase->fresh(), PurchaseSource::Reconcile);
        }, 'review', true],
    ]);

    it('takes its interval and window from config/purchases.php', function () {
        expect(config('purchases.customer_refresh_seconds'))->toBe(30)->and(config('purchases.customer_refresh_window_minutes'))->toBe(10)
            ->and(config('purchases.overdue_after_minutes'))->toBe(15);
        config(['purchases.customer_refresh_seconds' => 45, 'purchases.customer_refresh_window_minutes' => 3]);
        cpvAt('2026-10-04 09:00:00');
        $user = puxCustomer();
        $purchase = cpvBuy($user, cpvPlan(), 'timeout');
        $this->actingAs($user);

        cpvAt('2026-10-04 09:02:59');
        $this->get(route('purchases.show', $purchase->reference))->assertSee('data-auto-refresh="45"', false)
            ->assertSee('This page checks for updates every 45 seconds.')->assertSee('window.location.reload(), 45000)', false);
        cpvAt('2026-10-04 09:03:00');
        $this->get(route('purchases.show', $purchase->reference))->assertDontSee('data-auto-refresh', false);
    });

    it('adds no route and shows the page only to the purchase’s owner', function () {
        $owner = puxCustomer();
        $purchase = cpvBuy($owner, cpvPlan(), 'timeout');

        $this->actingAs(puxCustomer())->get(route('purchases.show', $purchase->reference))->assertNotFound();
        expect(collect(Route::getRoutes())->map->uri()->filter(fn ($uri) => str_starts_with($uri, 'purchases'))->values()->all())
            ->toBe(['purchases', 'purchases/{reference}']);
    });
});

describe('wallet links to purchases', function () {
    it('links purchase debits and refunds to their purchase in both lists, and nothing else', function () {
        $plan = cpvPlan();
        $user = puxCustomer(200_000); // funded by a test adjustment
        app(WalletService::class)->credit(Wallet::where('user_id', $user->id)->sole(), 10_000, LedgerEntryType::Funding, TransactionType::Funding,
            'Wallet funding', 'payment:PAY-TEST', null, ['payment' => 'PAY-TEST']);
        $ok = cpvBuy($user, $plan, 'succeeded');
        $failed = cpvBuy($user, $plan, 'failed_definite');

        $html = $this->actingAs($user)->get('/wallet')->assertOk()
            ->assertSee('data-payment-history-link', false)->assertDontSee('data-fund-wallet', false)->getContent();

        foreach ([$ok->debit_transaction_id => $ok, $failed->debit_transaction_id => $failed, $failed->refund_transaction_id => $failed] as $id => $purchase) {
            $transaction = Transaction::findOrFail($id);
            foreach ([cpvRow($html, 'data-transaction', $transaction->reference), cpvRow($html, 'data-ledger-entry', $transaction->entries()->sole()->reference)] as $row) {
                expect($row)->toContain('href="'.route('purchases.show', $purchase->reference).'"')->toContain('>View purchase</a>');
            }
        }
        foreach (Transaction::where('user_id', $user->id)->whereIn('type', ['adjustment', 'funding'])->get() as $transaction) {
            foreach ([cpvRow($html, 'data-transaction', $transaction->reference), cpvRow($html, 'data-ledger-entry', $transaction->entries()->sole()->reference)] as $row) {
                expect($row)->not->toBe('')->not->toContain('View purchase');
            }
        }
        expect(substr_count($html, 'data-purchase-link="'))->toBe(6);
        $this->get(route('purchases.show', $failed->reference))->assertOk()->assertSee('Purchase not completed');
    });

    it('links nothing without a valid purchase reference on a purchase transaction', function (TransactionType $type, LedgerEntryType $entryType, array $metadata) {
        $user = puxCustomer(0);
        app(WalletService::class)->credit(Wallet::where('user_id', $user->id)->sole(), 1_000, $entryType, $type, 'Test row', (string) Str::uuid(), null, $metadata);

        $html = $this->actingAs($user)->get('/wallet')->assertOk()->assertSee('Test row')->getContent();
        expect($html)->not->toContain('data-purchase-link')->not->toContain('View purchase')->not->toContain('<script>alert');
    })->with([
        'an adjustment naming a purchase' => [TransactionType::Adjustment, LedgerEntryType::AdjustmentCredit, ['purchase' => 'PUR-01K6PZ0Q3H8V9W2X4Y6Z8A0B1C']],
        'a funding naming a purchase' => [TransactionType::Funding, LedgerEntryType::Funding, ['purchase' => 'PUR-01K6PZ0Q3H8V9W2X4Y6Z8A0B1C']],
        'no reference' => [TransactionType::Purchase, LedgerEntryType::PurchaseRefund, []],
        'a malformed reference' => [TransactionType::Purchase, LedgerEntryType::PurchaseRefund, ['purchase' => 'PUR-123']],
        'a lower-case reference' => [TransactionType::Purchase, LedgerEntryType::PurchaseRefund, ['purchase' => 'pur-01k6pz0q3h8v9w2x4y6z8a0b1c']],
        'a reference with a trailing newline' => [TransactionType::Purchase, LedgerEntryType::PurchaseRefund, ['purchase' => "PUR-01K6PZ0Q3H8V9W2X4Y6Z8A0B1C\n"]],
        'markup' => [TransactionType::Purchase, LedgerEntryType::PurchaseRefund, ['purchase' => '<script>alert(1)</script>']],
        'a list instead of a reference' => [TransactionType::Purchase, LedgerEntryType::PurchaseRefund, ['purchase' => ['PUR-01K6PZ0Q3H8V9W2X4Y6Z8A0B1C']]],
    ]);

    it('links only to the customer’s own purchases, and another customer’s purchase never opens', function () {
        $plan = cpvPlan();
        $ada = puxCustomer(200_000);
        $bola = puxCustomer(200_000);
        $adas = cpvBuy($ada, $plan, 'succeeded', '08011112222');
        $bolas = cpvBuy($bola, $plan, 'succeeded', '08033334444');

        $html = $this->actingAs($bola)->get('/wallet')->assertOk()->getContent();
        expect(substr_count($html, 'data-purchase-link="'.$bolas->reference.'"'))->toBe(2)
            ->and(substr_count($html, 'data-purchase-link="'))->toBe(2)
            ->and($html)->not->toContain($adas->reference);
        $this->get(route('purchases.show', $adas->reference))->assertNotFound();

        // Even a purchase transaction on Bola's wallet naming Ada's purchase would not open it for Bola.
        app(WalletService::class)->credit(Wallet::where('user_id', $bola->id)->sole(), 1_000, LedgerEntryType::PurchaseRefund, TransactionType::Purchase,
            'Tampered', (string) Str::uuid(), null, ['purchase' => $adas->reference]);
        $this->get('/wallet')->assertSee('data-purchase-link="'.$adas->reference.'"', false);
        $this->get(route('purchases.show', $adas->reference))->assertNotFound()->assertDontSee('08011112222');
    });

    it('adds no query per row', function () {
        $plan = cpvPlan();
        $few = puxCustomer(5_000_000);
        cpvBuy($few, $plan, 'succeeded');
        $many = puxCustomer(5_000_000);
        foreach (['succeeded', 'failed_definite', 'succeeded', 'failed_definite', 'timeout', 'succeeded'] as $script) {
            cpvBuy($many, $plan, $script);
        }
        cpvQueries($this, $many, '/wallet'); // warm the settings and permission caches

        [$fewAll] = cpvQueries($this, $few, '/wallet');
        [$manyAll] = cpvQueries($this, $many, '/wallet');

        expect($manyAll)->toBe($fewAll);
        expect(substr_count($this->actingAs($many)->get('/wallet')->getContent(), 'data-purchase-link="'))->toBe(16); // 8 money rows in each list
    });
});
