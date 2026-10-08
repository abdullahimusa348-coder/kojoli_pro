<?php

use App\Actions\Admin\Referrals\ActOnCommission;
use App\Models\Commission;
use App\Models\CommissionAction;
use App\Models\Purchase;
use App\Models\SystemUser;
use App\Services\Wallet\WalletService;
use App\Support\Enums\SystemRole;
use App\Support\Referrals\CommissionActionType;
use App\Support\Referrals\CommissionStatus;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Providers\FakeProvider;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12 CP5: the minimum admin workflow for reversing or cancelling a
 * commission. The Commissions tab lists every commission with its derived
 * status; the commission page shows its details and, to staff with
 * referrals.manage while it has no action, the Reverse and Cancel forms.
 * referrals.view is enough to look; referrals.manage (with referrals.view
 * and admin access, on an active account) is needed to act, on the routes
 * and again inside ActOnCommission. Neither page shows the buyer or
 * anything the purchase delivered. The actions themselves are tested in
 * CommissionActionTest.
 */

beforeEach(function () {
    cmxDrivers();
    Http::preventStrayRequests();
});

/** Staff holding the built-in $role. */
function cadStaff(SystemRole $role): SystemUser
{
    $staff = cmxStaff();
    $staff->syncRoles([$role->value]);

    return $staff->fresh();
}

/** Every row of the commission and money tables, to show that nothing was written. */
function cadRows(): array
{
    return collect(['commissions', 'commission_actions', 'transactions', 'wallet_ledger_entries', 'wallets'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
}

/** The hidden one-time token of the commission page's $type form ('' when the form is not there). */
function cadToken(string $html, CommissionActionType $type): string
{
    preg_match('/<form [^>]*data-'.$type->value.'-form[^>]*>.*?name="token" value="([^"]+)"/s', $html, $match);

    return $match[1] ?? '';
}

dataset('cad actions', ['reversal' => [CommissionActionType::Reversal], 'cancellation' => [CommissionActionType::Cancellation]]);

/** Submits $type's form for $commission as $staff from the commission page (expecting success unless $succeeds is false). */
function cadAct($test, Commission $commission, CommissionActionType $type, SystemUser $staff, array $overrides = [], bool $succeeds = true)
{
    $response = $test->actingAs($staff, 'admin')->from(route('admin.referrals.commissions.show', $commission))
        ->post(cmxActionUrl($commission, $type), cmxActionForm($commission, $type, $staff, $overrides));

    return $succeeds ? $response->assertSessionHasNoErrors() : $response;
}

describe('commissions tab', function () {
    it('lists every commission newest first, with its status, amount, referrer and purchase, never the buyer', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        $referrer->forceFill(['name' => 'Amaka Referrer', 'email' => 'amaka.referrer@example.com'])->save();
        [, $first] = cmxCommission(0, $referrer);
        [, $second] = cmxCommission(0, $referrer);
        [, $third] = cmxCommission(0, $referrer);
        $buyer = $first->purchase->user;
        $buyer->forceFill(['name' => 'Buyer Wqvcpfive', 'email' => 'buyer.wqvcpfive@example.com'])->save();
        cadAct($this, $first, CommissionActionType::Reversal, $staff);
        cadAct($this, $second, CommissionActionType::Cancellation, $staff);

        $html = $this->actingAs($staff, 'admin')->get('/admin/referrals')->assertOk()
            ->assertSee('Amaka Referrer')->assertSee('amaka.referrer@example.com')->assertSee('₦12.50')
            ->assertSee($first->purchase->reference)->assertSee('Data')
            ->assertSee('href="'.route('admin.referrals.commissions.show', $first).'"', false)
            ->assertDontSee('Wqvcpfive')->assertDontSee('buyer.wqvcpfive')->assertDontSee('08012345678')
            ->getContent();

        preg_match_all('/data-commission="(COM-[0-9A-Z]{26})"/', $html, $rows);
        preg_match_all('/data-commission-status="(\w+)"/', $html, $statuses);
        expect($rows[1])->toBe([$third->reference, $second->reference, $first->reference])
            ->and($statuses[1])->toBe([CommissionStatus::Credited->value, CommissionStatus::Cancelled->value, CommissionStatus::Reversed->value]);
    });

    it('shows an empty state, and a no-results state for a search', function () {
        $this->actingAs(cmxStaff(), 'admin');

        $this->get('/admin/referrals')->assertOk()->assertSee('data-commissions-empty', false)->assertSee('No commissions yet');
        cmxCommission();
        $this->get('/admin/referrals?q=nobody-at-all')->assertOk()->assertSee('No commissions found')->assertSee('Try a different search.');
    });

    it('searches by commission or purchase reference in any case, or by the referrer\'s name or email', function () {
        $staff = cmxStaff();
        $ada = cmxReferrer();
        $ada->forceFill(['name' => 'Ada Lovelace', 'email' => 'ada.l@example.com'])->save();
        $tunde = cmxReferrer();
        $tunde->forceFill(['name' => 'Tunde Bello', 'email' => 'tunde.b@example.com'])->save();
        [, $adas] = cmxCommission(0, $ada);
        [, $tundes] = cmxCommission(0, $tunde);
        $this->actingAs($staff, 'admin');
        $listed = function (string $q) {
            preg_match_all('/data-commission="(COM-[0-9A-Z]{26})"/', $this->get('/admin/referrals?q='.urlencode($q))->assertOk()->getContent(), $rows);

            return $rows[1];
        };

        expect($listed(strtolower($adas->reference)))->toBe([$adas->reference])
            ->and($listed(' '.$tundes->reference.' '))->toBe([$tundes->reference])
            ->and($listed(strtolower($tundes->purchase->reference)))->toBe([$tundes->reference])
            ->and($listed('lovelace'))->toBe([$adas->reference])
            ->and($listed('TUNDE.B@'))->toBe([$tundes->reference])
            ->and($listed('%'))->toBe([])
            ->and($listed($adas->purchase->user->email))->toBe([]); // the buyer is not a search key
        $this->get('/admin/referrals?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
    });

    it('pages the commissions 25 at a time', function () {
        $referrer = cmxReferrer();
        foreach (range(1, 26) as $i) {
            cmxCommission(0, $referrer);
        }
        $this->actingAs(cmxStaff(), 'admin');

        expect(substr_count($this->get('/admin/referrals')->assertOk()->getContent(), 'data-commission="'))->toBe(25)
            ->and(substr_count($this->get('/admin/referrals?page=2')->assertOk()->getContent(), 'data-commission="'))->toBe(1);
    });

    it('escapes referrer names and emails', function () {
        $referrer = cmxReferrer();
        $referrer->forceFill(['name' => '<script>alert(1)</script>', 'email' => 'x"><b>bold</b>@example.com'])->save();
        [, $commission] = cmxCommission(0, $referrer);
        $this->actingAs(cmxStaff(), 'admin');

        foreach (['/admin/referrals', route('admin.referrals.commissions.show', $commission)] as $page) {
            $this->get($page)->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>bold</b>', false)
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        }
    });
});

describe('commission page', function () {
    it('shows the amount, rate and cap, referrer, purchase and credit, with the Reverse and Cancel forms for referrals.manage', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        $commission->purchase->user->forceFill(['name' => 'Buyer Wqvcpfive', 'email' => 'buyer.wqvcpfive@example.com'])->save();

        $response = $this->actingAs($staff, 'admin')->get(route('admin.referrals.commissions.show', $commission))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee($commission->reference)->assertSee('data-commission-status="credited"', false)->assertSee('₦12.50')->assertSee('₦500.00')
            ->assertSee('2.5%, cap ₦1,000.00')->assertSee($referrer->name)->assertSee($referrer->email)->assertSee('Customer #'.$referrer->id)
            ->assertSee($commission->purchase->reference)->assertSee($commission->creditTransaction->reference)
            ->assertSee('href="'.route('admin.users.show', $referrer).'"', false)
            ->assertSee('href="'.route('admin.purchases.show', $commission->purchase).'"', false)
            ->assertSee('href="'.route('admin.transactions.show', $commission->creditTransaction).'"', false)
            ->assertSee('data-reversal-form', false)->assertSee('data-cancellation-form', false)
            ->assertSee('action="'.cmxActionUrl($commission, CommissionActionType::Reversal).'"', false)
            ->assertSee('action="'.cmxActionUrl($commission, CommissionActionType::Cancellation).'"', false)
            ->assertSee('I confirm: debit ₦12.50 from the referrer\'s Main Wallet.')
            ->assertDontSee('Wqvcpfive')->assertDontSee('buyer.wqvcpfive')->assertDontSee('08012345678');
        $html = $response->getContent();

        expect(substr_count($html, 'name="confirm" value="1"'))->toBe(2)
            ->and($html)->not->toMatch('/name="confirm"[^>]*checked/') // never pre-ticked
            ->and(cadToken($html, CommissionActionType::Reversal))->not->toBe('')
            ->and(cadToken($html, CommissionActionType::Cancellation))->not->toBe('')
            ->and(cadToken($html, CommissionActionType::Reversal))->not->toBe(cadToken($html, CommissionActionType::Cancellation));
    });

    it('reverses and cancels with the tokens of the page itself, each form only for its own action', function (CommissionActionType $type) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        $page = route('admin.referrals.commissions.show', $commission);
        $html = $this->actingAs($staff, 'admin')->get($page)->getContent();
        $other = $type === CommissionActionType::Reversal ? CommissionActionType::Cancellation : CommissionActionType::Reversal;
        $rows = cadRows();

        // The other form's token, posted to this form's route, is refused.
        $this->from($page)->post(cmxActionUrl($commission, $type), ['reason' => 'Taken back after a review of the purchase.', 'confirm' => '1',
            'token' => cadToken($html, $other)])->assertRedirect($page)->assertSessionHasErrorsIn($type->value, 'action');
        expect(cadRows())->toBe($rows);

        $this->from($page)->post(cmxActionUrl($commission, $type), ['reason' => 'Taken back after a review of the purchase.', 'confirm' => '1',
            'token' => cadToken($html, $type)])->assertRedirect($page)->assertSessionHasNoErrors();
        expect($commission->fresh()->status())->toBe($type->status());

        // Neither form's token can act again, on either route.
        foreach ([$type, $other] as $route) {
            foreach ([$type, $other] as $token) {
                $this->from($page)->post(cmxActionUrl($commission, $route), ['reason' => 'Taken back after a review of the purchase.', 'confirm' => '1',
                    'token' => cadToken($html, $token)])->assertSessionHasErrorsIn($route->value, 'action');
            }
        }
        expect(CommissionAction::count())->toBe(1);
    })->with('cad actions');

    it('shows the action once taken, with no forms: type, reference, staff member, time, money and the escaped reason', function (CommissionActionType $type) {
        $staff = cmxStaff();
        $staff->forceFill(['name' => 'Ngozi Staffmember'])->save();
        [, $commission] = cmxCommission(5_000);
        cadAct($this, $commission, $type, $staff, ['reason' => "Checked twice.\n<script>alert('x')</script> & done"]);
        $action = CommissionAction::sole();

        $response = $this->get(route('admin.referrals.commissions.show', $commission))->assertOk()
            ->assertSee('data-commission-status="'.$type->status()->value.'"', false)
            ->assertSee('data-commission-action="'.$type->value.'"', false)
            ->assertSee($action->reference)->assertSee('Ngozi Staffmember')->assertSee($action->created_at->format('j M Y, H:i:s'))
            ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; &amp; done', false)->assertDontSee("<script>alert('x')</script>", false)
            ->assertDontSee('data-reversal-form', false)->assertDontSee('data-cancellation-form', false)->assertDontSee('name="token"', false);

        if ($type === CommissionActionType::Reversal) {
            $response->assertSee('₦12.50 debited as a separate "Commission reversal"', false)->assertSee($action->reversalTransaction->reference)
                ->assertSee('href="'.route('admin.transactions.show', $action->reversalTransaction).'"', false);
        } else {
            $response->assertSee('None moved.');
        }
    })->with('cad actions');

    it('keeps showing the action and its staff member once they are disabled or removed', function () {
        $staff = cmxStaff();
        $staff->forceFill(['name' => 'Chidi Formerstaff'])->save();
        [, $commission] = cmxCommission(5_000);
        cadAct($this, $commission, CommissionActionType::Cancellation, $staff);
        $staff->forceFill(['status' => 'disabled'])->save();
        $staff->delete();

        $this->actingAs(cmxStaff(), 'admin')->get(route('admin.referrals.commissions.show', $commission))->assertOk()
            ->assertSee('data-commission-action="cancellation"', false)->assertSee('Chidi Formerstaff');
    });

    it('shows the messages after an action and after a refusal, keeping the reason typed for the refused form only', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(); // 1,250 kobo
        $page = route('admin.referrals.commissions.show', $commission);
        $wallets = app(WalletService::class);
        $wallets->debit($wallets->walletFor($referrer), 1, LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Test spending');

        cadAct($this, $commission, CommissionActionType::Reversal, $staff, ['reason' => 'A reason that stays in the form.'], false)
            ->assertRedirect($page);
        $html = $this->get($page)->assertOk()->assertSee(ActOnCommission::TOO_LOW)->getContent();
        preg_match('/<textarea id="reversal-reason"[^>]*>([^<]*)<\/textarea>/', $html, $reversal);
        preg_match('/<textarea id="cancellation-reason"[^>]*>([^<]*)<\/textarea>/', $html, $cancellation);
        expect($reversal[1])->toBe('A reason that stays in the form.')->and($cancellation[1])->toBe('');

        cadAct($this, $commission, CommissionActionType::Cancellation, $staff);
        $this->get($page)->assertOk()->assertSee('Commission cancelled. No money was moved.');
    });

    it('shows a NIN purchase\'s commission without the NIN, its masked form or anything the purchase delivered', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        cmxSetting('nin', 250, 100_000);
        $buyer = cmxReferred($referrer);
        $nin = (string) random_int(10_000_000_000, 99_999_999_999);
        FakeProvider::$purchaseScript = ['succeeded'];
        FakeProvider::$resultScript = [FakeProvider::fixtureFields(2, ['FIXTURE-NIN-RESULT-ONE', 'FIXTURE-NIN-RESULT-TWO'])];
        $purchase = puxService()->purchase($buyer, cmxPlan('nin', 50_000), $nin, null, (string) Str::uuid(), null, true);
        $commission = Commission::where('purchase_id', $purchase->id)->sole();
        $masked = Purchase::findOrFail($purchase->id)->displayRecipient();
        expect($masked)->not->toBeNull();

        $this->actingAs($staff, 'admin');
        foreach (['/admin/referrals', route('admin.referrals.commissions.show', $commission)] as $page) {
            $this->get($page)->assertOk()->assertSee($commission->reference)->assertSee('NIN')
                ->assertDontSee($nin)->assertDontSee($masked)->assertDontSee('FIXTURE-NIN-RESULT')->assertDontSee('Fixture 1');
        }
    });
});

describe('roles and permissions', function () {
    it('lets referrals.view look at commissions, never act: no forms, no tokens and 403 on both actions', function (CommissionActionType $type) {
        $viewer = cmxStaffWith(['admin.access', 'referrals.view']);
        [, $commission] = cmxCommission(5_000);
        $rows = cadRows();
        $this->actingAs($viewer, 'admin');

        $this->get('/admin/referrals')->assertOk()->assertSee($commission->reference);
        $this->get(route('admin.referrals.commissions.show', $commission))->assertOk()->assertSee($commission->reference)
            ->assertSee('data-no-action', false)->assertDontSee('data-reversal-form', false)->assertDontSee('data-cancellation-form', false)
            ->assertDontSee('name="token"', false)
            // links only for what the staff member may open
            ->assertDontSee('href="'.route('admin.users.show', $commission->referrer).'"', false)
            ->assertDontSee('href="'.route('admin.purchases.show', $commission->purchase).'"', false)
            ->assertDontSee('href="'.route('admin.transactions.show', $commission->creditTransaction).'"', false);
        // Even with a valid token of a manager, the route refuses a viewer.
        $this->post(cmxActionUrl($commission, $type), cmxActionForm($commission, $type, cmxStaff()))->assertForbidden();
        $this->post(cmxActionUrl($commission, $type), cmxActionForm($commission, $type, $viewer))->assertForbidden();

        expect(cadRows())->toBe($rows);
    })->with('cad actions');

    it('lets referrals.view with referrals.manage reverse and cancel', function (CommissionActionType $type) {
        $manager = cmxStaffWith(['admin.access', 'referrals.view', 'referrals.manage']);
        [, $commission] = cmxCommission(5_000);

        $html = $this->actingAs($manager, 'admin')->get(route('admin.referrals.commissions.show', $commission))->assertOk()->getContent();
        $this->post(cmxActionUrl($commission, $type), ['reason' => 'Taken back after a review of the purchase.', 'confirm' => '1',
            'token' => cadToken($html, $type)])->assertSessionHasNoErrors();

        expect($commission->fresh()->status())->toBe($type->status())->and(CommissionAction::sole()->acted_by)->toBe($manager->id);
    })->with('cad actions');

    it('returns 403 on the commission pages and actions for the other built-in roles', function (SystemRole $role) {
        [, $commission] = cmxCommission(5_000);
        $rows = cadRows();
        $this->actingAs(cadStaff($role), 'admin');

        $this->get('/admin/referrals')->assertForbidden();
        $this->get(route('admin.referrals.commissions.show', $commission))->assertForbidden();
        foreach (CommissionActionType::cases() as $type) {
            $this->post(cmxActionUrl($commission, $type), cmxActionForm($commission, $type, cmxStaff()))->assertForbidden();
        }

        expect(cadRows())->toBe($rows);
    })->with([SystemRole::Manager, SystemRole::Support, SystemRole::Finance, SystemRole::Viewer]);

    it('needs referrals.view and admin access alongside referrals.manage', function () {
        [, $commission] = cmxCommission(5_000);
        $rows = cadRows();

        $noView = cmxStaffWith(['admin.access', 'referrals.manage']);
        $this->actingAs($noView, 'admin');
        $this->get(route('admin.referrals.commissions.show', $commission))->assertForbidden();
        foreach (CommissionActionType::cases() as $type) {
            $this->post(cmxActionUrl($commission, $type), cmxActionForm($commission, $type, $noView))->assertForbidden();
        }

        $noAccess = cmxStaffWith(['referrals.view', 'referrals.manage']);
        $this->actingAs($noAccess, 'admin');
        $this->get(route('admin.referrals.commissions.show', $commission))->assertRedirect(route('admin.login'));
        foreach (CommissionActionType::cases() as $type) {
            $this->post(cmxActionUrl($commission, $type), cmxActionForm($commission, $type, $noAccess))->assertRedirect(route('admin.login'));
        }

        expect(cadRows())->toBe($rows);
    });

    it('guards the page with admin access and referrals.view, and both actions also with referrals.manage and a rate limit', function () {
        $middleware = fn (string $name) => Route::getRoutes()->getByName($name)->gatherMiddleware();
        $view = ['web', 'auth:admin', 'staff.active', 'permission:admin.access,admin', 'permission:referrals.view,admin'];

        expect($middleware('admin.referrals'))->toBe($view)
            ->and($middleware('admin.referrals.commissions.show'))->toBe($view)
            ->and($middleware('admin.referrals.commissions.reverse'))->toBe([...$view, 'permission:referrals.manage,admin', 'throttle:30,1'])
            ->and($middleware('admin.referrals.commissions.cancel'))->toBe([...$view, 'permission:referrals.manage,admin', 'throttle:30,1']);
    });

    it('keeps guests and customers out', function () {
        [$referrer, $commission] = cmxCommission(5_000);
        $rows = cadRows();
        $form = fn (CommissionActionType $type) => cmxActionForm($commission, $type, cmxStaff());

        foreach ([null, $referrer] as $customer) {
            if ($customer !== null) {
                $this->actingAs($customer, 'web');
            }
            $this->get('/admin/referrals')->assertRedirect(route('admin.login'));
            $this->get(route('admin.referrals.commissions.show', $commission))->assertRedirect(route('admin.login'));
            foreach (CommissionActionType::cases() as $type) {
                $this->post(cmxActionUrl($commission, $type), $form($type))->assertRedirect(route('admin.login'));
            }
        }

        expect(cadRows())->toBe($rows);
    });

    it('signs out disabled staff instead of acting, even with a token issued while they were active', function (CommissionActionType $type) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        $form = cmxActionForm($commission, $type, $staff);
        $rows = cadRows();
        $staff->forceFill(['status' => 'disabled'])->save();

        $this->actingAs($staff, 'admin')->post(cmxActionUrl($commission, $type), $form)->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');

        expect(cadRows())->toBe($rows);
    })->with('cad actions');

    it('refuses the action once the staff member loses referrals.manage after opening the page', function (CommissionActionType $type) {
        $staff = cmxStaffWith(['admin.access', 'referrals.view', 'referrals.manage']);
        [, $commission] = cmxCommission(5_000);
        $html = $this->actingAs($staff, 'admin')->get(route('admin.referrals.commissions.show', $commission))->getContent();
        $rows = cadRows();
        $staff->roles->first()->revokePermissionTo('referrals.manage');

        $this->post(cmxActionUrl($commission, $type), ['reason' => 'Taken back after a review of the purchase.', 'confirm' => '1',
            'token' => cadToken($html, $type)])->assertForbidden();

        expect(cadRows())->toBe($rows);
    })->with('cad actions');

    it('offers no route that edits or deletes a commission or an action', function () {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'commission') && ! str_contains($route->uri(), 'rates'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->values()->all();

        expect($routes)->toBe(['GET|HEAD admin/referrals/commissions/{commission}', 'POST admin/referrals/commissions/{commission}/reverse',
            'POST admin/referrals/commissions/{commission}/cancel']);
    });
});
