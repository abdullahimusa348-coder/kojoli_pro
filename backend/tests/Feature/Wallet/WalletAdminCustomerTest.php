<?php

use App\Actions\Admin\Wallet\AdjustWallet;
use App\Actions\Admin\Wallet\ReverseAdjustment;
use App\Actions\Admin\Wallet\SetWalletStatus;
use App\Exceptions\Wallet\InvalidAmount;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Settings\SettingsStore;
use App\Services\Wallet\WalletService;
use App\Support\Enums\SystemRole;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(SettingsSeeder::class);
    Http::preventStrayRequests();
});

function waStaff(SystemRole|string $role = SystemRole::SuperAdmin): SystemUser
{
    $staff = SystemUser::factory()->create();
    $staff->assignRole($role instanceof SystemRole ? $role->value : $role);

    return $staff;
}

function waRole(array $permissions): SystemUser
{
    $role = Role::create(['name' => 'WA '.implode(' ', $permissions), 'guard_name' => 'admin'])->givePermissionTo(['admin.access', ...$permissions]);

    return waStaff($role->name);
}

function waCustomer(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

/** Valid adjustment form data. */
function waForm(array $overrides = []): array
{
    return $overrides + ['direction' => 'credit', 'amount' => '1,000.50', 'reason' => 'Goodwill credit after support call', 'confirm' => '1', 'idempotency_key' => (string) Str::uuid()];
}

function waFund(User $customer, int $kobo): Transaction
{
    $wallets = app(WalletService::class);

    return $wallets->credit($wallets->walletFor($customer), $kobo, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Balance adjustment (credit)')->transaction;
}

describe('admin adjustments', function () {
    it('lists customer wallets with search and status filter', function () {
        $rich = waCustomer(['name' => 'Ada Funded', 'email' => 'ada@example.test']);
        waFund($rich, 250_000);
        $frozen = waCustomer(['name' => 'Bola Frozen']);
        app(WalletService::class)->setStatus(app(WalletService::class)->walletFor($frozen), WalletStatus::Frozen);
        waCustomer(['name' => 'Chidi Empty']);
        $this->actingAs(waStaff(), 'admin');

        $this->get('/admin/wallet')->assertOk()->assertDontSee('is not built yet')
            ->assertSee('Ada Funded')->assertSee('₦2,500.00')->assertSee('Chidi Empty')->assertSee('₦0.00');
        $this->get('/admin/wallet?q=ada@')->assertSee('Ada Funded')->assertDontSee('Bola Frozen');
        $this->get('/admin/wallet?status=frozen')->assertSee('Bola Frozen')->assertDontSee('Ada Funded')->assertDontSee('Chidi Empty');
        $this->get('/admin/wallet?status=none')->assertSee('Chidi Empty')->assertDontSee('Ada Funded');
        $this->get('/admin/wallet?status=bogus')->assertSessionHasErrors('status');
    });

    it('shows a customer without a wallet as ₦0.00 and does not create one by viewing', function () {
        $customer = waCustomer();
        $this->actingAs(waStaff(), 'admin');

        $this->get("/admin/wallet/{$customer->id}")->assertOk()->assertSee('₦0.00')->assertSee('No wallet activity yet')->assertSee('No ledger entries yet.');
        expect(Wallet::count())->toBe(0);
    });

    it('credits a wallet with a full transaction and ledger record', function () {
        $customer = waCustomer();
        $staff = waStaff();
        $this->actingAs($staff, 'admin');

        $this->post("/admin/wallet/{$customer->id}/adjust", waForm())->assertRedirect(route('admin.wallet.show', $customer))->assertSessionHas('status');

        $tx = Transaction::first();
        expect($tx->type)->toBe(TransactionType::Adjustment)->and($tx->direction)->toBe(Direction::Credit)->and($tx->amount_kobo)->toBe(100_050)
            ->and($tx->status)->toBe(TransactionStatus::Successful)->and($tx->created_by)->toBe($staff->id)
            ->and($tx->internalReason())->toBe('Goodwill credit after support call')->and($tx->description)->toBe('Balance adjustment (credit)')
            ->and($tx->entries()->first()->created_by)->toBe($staff->id)
            ->and($customer->mainWallet()->balance_kobo)->toBe(100_050);
        $this->get("/admin/wallet/{$customer->id}")->assertSee('₦1,000.50')->assertSee($tx->reference)->assertSee('Goodwill credit after support call');
    });

    it('debits a wallet and refuses overdrafts', function () {
        $customer = waCustomer();
        waFund($customer, 50_000);
        $this->actingAs(waStaff(), 'admin');

        $this->post("/admin/wallet/{$customer->id}/adjust", waForm(['direction' => 'debit', 'amount' => '200']))->assertSessionHasNoErrors();
        $this->from("/admin/wallet/{$customer->id}")->post("/admin/wallet/{$customer->id}/adjust", waForm(['direction' => 'debit', 'amount' => '300.01']))
            ->assertSessionHasErrors(['amount' => 'The wallet balance is not enough for this debit.']);

        expect($customer->mainWallet()->balance_kobo)->toBe(30_000)->and(Transaction::count())->toBe(2);
    });

    it('never posts twice for a repeated form submission', function () {
        $customer = waCustomer();
        $this->actingAs(waStaff(), 'admin');
        $form = waForm();

        $this->post("/admin/wallet/{$customer->id}/adjust", $form);
        $this->post("/admin/wallet/{$customer->id}/adjust", $form)->assertSessionHas('status', fn ($s) => str_contains($s, 'already recorded'));
        $this->post("/admin/wallet/{$customer->id}/adjust", ['amount' => '5'] + $form)->assertSessionHasErrors(['amount' => 'This request key was already used for a different operation.']);

        expect(Transaction::count())->toBe(1)->and(WalletLedgerEntry::count())->toBe(1)->and($customer->mainWallet()->balance_kobo)->toBe(100_050);
    });

    it('validates the adjustment form', function (array $input, string $field) {
        $customer = waCustomer();
        $this->actingAs(waStaff(), 'admin');

        $this->post("/admin/wallet/{$customer->id}/adjust", waForm($input))->assertSessionHasErrors($field);
        expect(Transaction::count())->toBe(0)->and(Wallet::count())->toBe(0);
    })->with([
        'missing confirmation' => [['confirm' => null], 'confirm'],
        'short reason' => [['reason' => 'too short'], 'reason'],
        'missing reason' => [['reason' => ''], 'reason'],
        'zero amount' => [['amount' => '0'], 'amount'],
        'negative amount' => [['amount' => '-5'], 'amount'],
        'three decimals' => [['amount' => '1.005'], 'amount'],
        'text amount' => [['amount' => 'ten naira'], 'amount'],
        'above safety limit' => [['amount' => '10,000,000.01'], 'amount'],
        'bad direction' => [['direction' => 'sideways'], 'direction'],
        'missing token' => [['idempotency_key' => ''], 'idempotency_key'],
        'bad token' => [['idempotency_key' => 'not-a-uuid'], 'idempotency_key'],
    ]);

    it('uses the configurable pricing.max_amount_kobo safety limit', function () {
        $customer = waCustomer();
        app(SettingsStore::class)->set('pricing.max_amount_kobo', 50_000);
        $this->actingAs(waStaff(), 'admin');

        $this->post("/admin/wallet/{$customer->id}/adjust", waForm(['amount' => '500.01']))->assertSessionHasErrors(['amount' => 'The amount may not be more than ₦500.00 (system maximum).']);
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm(['amount' => '500']))->assertSessionHasNoErrors();
        expect(fn () => app(AdjustWallet::class)->handle($customer, Direction::Credit, 50_001, 'Above the safety limit', (string) Str::uuid(), waStaff()))
            ->toThrow(InvalidAmount::class);
    });

    it('reverses an adjustment once with a compensating entry', function () {
        $customer = waCustomer();
        $this->actingAs(waStaff(), 'admin');
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm(['amount' => '400']));
        $tx = Transaction::first();
        $key = (string) Str::uuid();

        $this->post("/admin/wallet/{$customer->id}/transactions/{$tx->id}/reverse", ['reversal_reason' => 'Posted to the wrong account', 'reversal_confirm' => '1', 'idempotency_key' => $key])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'reversed (WLE-'));
        $this->post("/admin/wallet/{$customer->id}/transactions/{$tx->id}/reverse", ['reversal_reason' => 'Posted to the wrong account', 'reversal_confirm' => '1', 'idempotency_key' => $key])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'already reversed'));
        $this->post("/admin/wallet/{$customer->id}/transactions/{$tx->id}/reverse", ['reversal_reason' => 'A second reversal attempt', 'reversal_confirm' => '1', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['reversal' => 'This transaction has already been reversed.']);

        expect($tx->fresh()->status)->toBe(TransactionStatus::Reversed)->and($customer->mainWallet()->balance_kobo)->toBe(0)
            ->and(WalletLedgerEntry::count())->toBe(2)->and(WalletLedgerEntry::where('entry_type', 'reversal')->first()->metadata['reason'])->toBe('Posted to the wrong account');
        $this->get("/admin/wallet/{$customer->id}")->assertSee('Reversed')->assertSee('Reversal of '.$tx->reference)->assertDontSee('data-reverse="'.$tx->reference.'"', false);
    });

    it('validates reversals and keeps them within the customer', function () {
        $customer = waCustomer();
        $other = waCustomer();
        $tx = waFund($customer, 1_000);
        $this->actingAs(waStaff(), 'admin');

        $this->post("/admin/wallet/{$customer->id}/transactions/{$tx->id}/reverse", ['reversal_reason' => 'short', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors(['reversal_reason', 'reversal_confirm']);
        $this->post("/admin/wallet/{$other->id}/transactions/{$tx->id}/reverse", ['reversal_reason' => 'Wrong customer in URL', 'reversal_confirm' => '1', 'idempotency_key' => (string) Str::uuid()])
            ->assertNotFound();
        expect($tx->fresh()->status)->toBe(TransactionStatus::Successful);
    });

    it('freezes and unfreezes a wallet', function () {
        $customer = waCustomer();
        waFund($customer, 1_000);
        $this->actingAs(waStaff(), 'admin');

        $this->patch("/admin/wallet/{$customer->id}/status", ['status' => 'frozen'])->assertSessionHas('status');
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm(['direction' => 'debit', 'amount' => '1']))
            ->assertSessionHasErrors(['amount' => 'This wallet is frozen; debits are blocked.']);
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm(['amount' => '1']))->assertSessionHasNoErrors();
        $this->get("/admin/wallet/{$customer->id}")->assertSee('Frozen')->assertSee('Unfreeze wallet');

        $this->patch("/admin/wallet/{$customer->id}/status", ['status' => 'active']);
        $this->patch("/admin/wallet/{$customer->id}/status", ['status' => 'closed'])->assertSessionHasErrors('status');
        expect($customer->mainWallet()->status)->toBe(WalletStatus::Active)->and($customer->mainWallet()->balance_kobo)->toBe(1_100);
    });
});

describe('transactions module', function () {
    it('lists, searches and filters transactions', function () {
        $ada = waCustomer(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        $bola = waCustomer(['name' => 'Bola Tinubu-Test', 'email' => 'bola@example.test']);
        $credit = waFund($ada, 5_000);
        $wallets = app(WalletService::class);
        $debit = $wallets->debit($wallets->walletFor($ada), 1_000, LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Balance adjustment (debit)')->transaction;
        $bolaTx = waFund($bola, 2_000);
        $wallets->reverse($bolaTx, 'Reversal for the filter test');
        $this->actingAs(waStaff(), 'admin');

        $this->get('/admin/transactions')->assertOk()->assertDontSee('is not built yet')
            ->assertSee($credit->reference)->assertSee($debit->reference)->assertSee($bolaTx->reference);
        $this->get('/admin/transactions?q='.$credit->reference)->assertSee($credit->reference)->assertDontSee($debit->reference);
        $this->get('/admin/transactions?q=bola@')->assertSee($bolaTx->reference)->assertDontSee($credit->reference);
        $this->get('/admin/transactions?direction=debit')->assertSee($debit->reference)->assertDontSee($credit->reference);
        $this->get('/admin/transactions?status=reversed')->assertSee($bolaTx->reference)->assertDontSee($debit->reference);
        $this->get('/admin/transactions?type=adjustment&from='.now()->toDateString().'&to='.now()->toDateString())->assertSee($credit->reference);
        $this->get('/admin/transactions?from='.now()->addDay()->toDateString())->assertSee('No transactions found');
        $this->get('/admin/transactions?status=refunded')->assertSessionHasErrors('status');
        $this->get('/admin/transactions?from=2026-10-05&to=2026-10-01')->assertSessionHasErrors('to');

        $this->get("/admin/transactions/{$bolaTx->id}")->assertOk()->assertSee('Reversal of '.$bolaTx->reference)->assertSee('Reversal for the filter test')->assertSee('Open customer wallet');
    });

    it('shows real totals and recent transactions on the admin dashboard', function () {
        waFund(waCustomer(['name' => 'Dash Customer']), 123_456);
        $this->actingAs(waStaff(), 'admin');

        $this->get('/admin')->assertSee('Total held in customer wallets')->assertSee('₦1,234.56')
            ->assertSee('data-recent-transaction="', false)->assertSee('Dash Customer')
            ->assertSee('No data yet: service purchases arrive in Phase 10');
    });
});

describe('authorization', function () {
    it('returns 403 for the other built-in roles everywhere', function (SystemRole $role) {
        $customer = waCustomer();
        $tx = waFund($customer, 1_000);
        $this->actingAs(waStaff($role), 'admin');

        foreach (['/admin/wallet', "/admin/wallet/{$customer->id}", '/admin/transactions', "/admin/transactions/{$tx->id}"] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm())->assertForbidden();
        $this->post("/admin/wallet/{$customer->id}/transactions/{$tx->id}/reverse", ['reversal_reason' => 'Not allowed to do this', 'reversal_confirm' => '1', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->patch("/admin/wallet/{$customer->id}/status", ['status' => 'frozen'])->assertForbidden();

        expect(Transaction::count())->toBe(1)->and($customer->mainWallet()->balance_kobo)->toBe(1_000)->and($customer->mainWallet()->status)->toBe(WalletStatus::Active);
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('gives each wallet and transactions permission exactly its access', function () {
        $customer = waCustomer();
        $tx = waFund($customer, 1_000);

        $this->actingAs(waRole(['wallet.view']), 'admin');
        $this->get("/admin/wallet/{$customer->id}")->assertOk()->assertDontSee('data-wallet-adjust', false)->assertDontSee('data-wallet-freeze', false)->assertDontSee('data-reverse=', false);
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm())->assertForbidden();
        $this->patch("/admin/wallet/{$customer->id}/status", ['status' => 'frozen'])->assertForbidden();
        $this->get('/admin/transactions')->assertForbidden();

        $this->actingAs(waRole(['wallet.view', 'wallet.adjust']), 'admin');
        $this->get("/admin/wallet/{$customer->id}")->assertSee('data-wallet-adjust', false)->assertDontSee('data-wallet-freeze', false);
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm(['amount' => '1']))->assertSessionHasNoErrors();
        $this->patch("/admin/wallet/{$customer->id}/status", ['status' => 'frozen'])->assertForbidden();

        $this->actingAs(waRole(['wallet.view', 'wallet.manage']), 'admin');
        $this->patch("/admin/wallet/{$customer->id}/status", ['status' => 'frozen'])->assertRedirect();
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm())->assertForbidden();

        $this->actingAs(waRole(['transactions.view']), 'admin');
        $this->get('/admin/transactions')->assertOk();
        $this->get("/admin/transactions/{$tx->id}")->assertOk()->assertDontSee('Open customer wallet');
        $this->get('/admin/wallet')->assertForbidden();

        expect(Transaction::count())->toBe(2)->and($customer->mainWallet()->status)->toBe(WalletStatus::Frozen);
    });

    it('requires wallet.view alongside wallet.adjust and wallet.manage', function () {
        $customer = waCustomer();
        $this->actingAs(waRole(['wallet.adjust', 'wallet.manage']), 'admin');

        $this->post("/admin/wallet/{$customer->id}/adjust", waForm())->assertForbidden();
        $this->patch("/admin/wallet/{$customer->id}/status", ['status' => 'frozen'])->assertForbidden();
        expect(Wallet::count())->toBe(0);
    });

    it('does not let services.*, pricing.* or providers.* reach wallets or transactions', function () {
        $customer = waCustomer();
        $this->actingAs(waRole(['services.view', 'services.update', 'pricing.view', 'pricing.update', 'providers.view', 'providers.update', 'providers.credentials', 'customers.view']), 'admin');

        $this->get('/admin/wallet')->assertForbidden();
        $this->get('/admin/transactions')->assertForbidden();
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm())->assertForbidden();
        $this->get("/admin/users/{$customer->id}")->assertOk()->assertDontSee('data-customer-wallet', false);
        $this->get('/admin')->assertDontSee('data-nav="wallet"', false)->assertDontSee('data-card="wallet-balance"', false);
    });

    it('shows the wallet on the admin customer page with wallet.view', function () {
        $customer = waCustomer();
        waFund($customer, 7_550);
        $this->actingAs(waRole(['customers.view', 'wallet.view']), 'admin');

        $this->get("/admin/users/{$customer->id}")->assertOk()->assertSee('data-customer-wallet', false)->assertSee('₦75.50')
            ->assertSee(route('admin.wallet.show', $customer), false);
    });

    it('seeds wallet.adjust for Super Admin only', function () {
        expect(Role::findByName('super-admin', 'admin')->hasPermissionTo('wallet.adjust'))->toBeTrue();
        foreach ([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer] as $role) {
            expect(Role::findByName($role->value, 'admin')->hasPermissionTo('wallet.adjust'))->toBeFalse();
        }
        $this->actingAs(waStaff(), 'admin')->get('/admin/roles/create')->assertSee('value="wallet.adjust"', false)->assertSee('Credit / debit / reverse');
    });

    it('keeps guests and customers out of the admin wallet area', function () {
        $customer = waCustomer();
        waFund($customer, 1_000);

        $this->get('/admin/wallet')->assertRedirect(route('admin.login'));
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm())->assertRedirect(route('admin.login'));

        $this->actingAs($customer, 'web');
        $this->get('/admin/wallet')->assertRedirect(route('admin.login'));
        $this->get('/admin/transactions')->assertRedirect(route('admin.login'));
        $this->post("/admin/wallet/{$customer->id}/adjust", waForm())->assertRedirect(route('admin.login'));

        expect(Transaction::count())->toBe(1)->and($customer->mainWallet()->balance_kobo)->toBe(1_000);
    });

    it('re-checks permissions inside the wallet actions', function () {
        $customer = waCustomer();
        $tx = waFund($customer, 1_000);
        $disabled = waStaff();
        $disabled->forceFill(['status' => 'disabled'])->save();

        foreach ([waStaff(SystemRole::Viewer), waRole(['wallet.view']), waRole(['transactions.view', 'transactions.manage']), $disabled] as $actor) {
            expect(fn () => app(AdjustWallet::class)->handle($customer, Direction::Credit, 100, 'Not allowed adjustment', (string) Str::uuid(), $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(ReverseAdjustment::class)->handle($tx, 'Not allowed reversal', (string) Str::uuid(), $actor))->toThrow(AuthorizationException::class)
                ->and(fn () => app(SetWalletStatus::class)->handle($customer, WalletStatus::Frozen, $actor))->toThrow(AuthorizationException::class);
        }
        expect(Transaction::count())->toBe(1)->and($tx->fresh()->status)->toBe(TransactionStatus::Successful)->and($customer->mainWallet()->status)->toBe(WalletStatus::Active);
    });

    it('has no update or delete routes for ledger entries or transactions', function () {
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'wallet') || str_contains($r->uri(), 'transaction'));

        expect($routes->filter(fn ($r) => array_intersect(['PUT', 'DELETE'], $r->methods()) !== [])->map->uri()->values()->all())->toBe([])
            ->and($routes->filter(fn ($r) => str_contains($r->uri(), 'ledger') || str_contains($r->uri(), 'entries'))->all())->toBe([]);
    });
});

describe('customer wallet', function () {
    it('shows ₦0.00 and empty history for a new customer without creating a wallet', function () {
        $customer = waCustomer();

        $this->actingAs($customer)->get('/wallet')->assertOk()
            ->assertSee('data-wallet-balance', false)->assertSee('₦0.00')->assertSee('No wallet activity yet.')->assertSee('No transactions yet.');
        $this->actingAs($customer)->get('/dashboard')->assertSee('data-wallet-card', false)->assertSee('₦0.00')->assertSee(route('wallet'), false);
        expect(Wallet::count())->toBe(0);
    });

    it('shows the real balance, ledger and transactions without internal notes', function () {
        $customer = waCustomer();
        $tx = waFund($customer, 250_075);
        app(AdjustWallet::class)->handle($customer, Direction::Debit, 7_500, 'Internal staff note: suspected duplicate', (string) Str::uuid(), waStaff());

        $html = $this->actingAs($customer)->get('/wallet')->assertOk()
            ->assertSee('₦2,425.75')->assertSee($tx->reference)->assertSee('Balance adjustment (debit)')->assertSee('Successful')->getContent();
        expect(str_contains($html, 'suspected duplicate'))->toBeFalse()->and(str_contains($html, 'Internal staff note'))->toBeFalse();
        $this->get('/dashboard')->assertSee('₦2,425.75');
    });

    it('only ever shows the customer their own wallet', function () {
        $ada = waCustomer();
        $bola = waCustomer();
        $adaTx = waFund($ada, 999_900);
        waFund($bola, 100);

        $html = $this->actingAs($bola)->get('/wallet')->assertOk()->assertSee('₦1.00')->getContent();
        expect(str_contains($html, $adaTx->reference))->toBeFalse()->and(str_contains($html, '₦9,999.00'))->toBeFalse();
        $this->get('/wallet?user='.$ada->id)->assertSee('₦1.00')->assertDontSee($adaTx->reference);
        $this->get("/admin/wallet/{$ada->id}")->assertRedirect(route('admin.login'));
    });

    it('offers no deposit, withdrawal, purchase or transfer actions', function () {
        $customer = waCustomer();
        waFund($customer, 1_000);

        $html = mb_strtolower($this->actingAs($customer)->get('/wallet')->getContent());
        foreach (['deposit', 'withdraw', 'fund wallet', 'top up', 'transfer', 'buy ', 'purchase', 'coming soon', 'action="'.route('wallet')] as $word) {
            expect(str_contains($html, $word))->toBeFalse("found \"{$word}\"");
        }
        expect(collect(Route::getRoutes())->filter(fn ($r) => $r->uri() === 'wallet' && $r->methods() !== ['GET', 'HEAD'])->all())->toBe([]);
    });

    it('lists Wallet in the customer navigation and requires sign-in', function () {
        $this->get('/wallet')->assertRedirect(route('login'));

        $this->actingAs(waCustomer())->get('/dashboard')->assertSee('data-customer-bottom-nav="wallet"', false);
        $this->get('/wallet')->assertSee('aria-current="page"', false);
    });

    it('signs out disabled customers', function () {
        $customer = waCustomer();
        $this->actingAs($customer);
        $customer->forceFill(['status' => 'disabled'])->save();

        $this->get('/wallet')->assertRedirect(route('login'));
    });

    it('adds no customer wallet API and makes no outgoing HTTP requests', function () {
        $customer = waCustomer();
        waFund($customer, 1_000);
        $this->actingAs($customer)->get('/wallet');

        expect(collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api') && preg_match('/(wallet|transaction|balance)/i', $r->uri()))->all())->toBe([]);
        Http::assertNothingSent();
    });
});
