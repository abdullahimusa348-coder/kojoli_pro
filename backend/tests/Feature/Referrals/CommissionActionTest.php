<?php

use App\Actions\Admin\Referrals\ActOnCommission;
use App\Actions\Admin\Wallet\ReverseAdjustment;
use App\Actions\Admin\Wallet\SetWalletStatus;
use App\Exceptions\Wallet\InvalidTransactionState;
use App\Models\Commission;
use App\Models\CommissionAction;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Referrals\ReferralSummary;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserStatus;
use App\Support\Referrals\CommissionActionToken;
use App\Support\Referrals\CommissionActionType;
use App\Support\Referrals\CommissionStatus;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/Referrals/helpers.php';

/*
 * Phase 12 CP5: reversing or cancelling a commission, on SQLite, through the
 * Reverse and Cancel forms of the admin commission page (ActOnCommission).
 * A commission has one action at most, ever. A reversal takes the full
 * amount back with a separate "Commission reversal" debit, the commission
 * and its credit left as they were; a cancellation moves no money, also on
 * a frozen wallet. Each form has its own one-time token for its commission,
 * action and staff member. Every refusal writes nothing. Pages and
 * permissions are in CommissionAdminTest, the MariaDB races in
 * tests/Concurrency/CommissionActionRaceTest.php.
 */

beforeEach(function () {
    cmxDrivers();
    Http::preventStrayRequests();
});

dataset('cat actions', ['reversal' => [CommissionActionType::Reversal], 'cancellation' => [CommissionActionType::Cancellation]]);

/** Every row of the commission and money tables, to show that nothing was written. */
function catRows(): array
{
    return collect(['commissions', 'commission_actions', 'transactions', 'wallet_ledger_entries', 'wallets'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
}

/** Submits $type's form for $commission as $staff, from the commission page. */
function catAct($test, Commission $commission, CommissionActionType $type, SystemUser $staff, array $overrides = [])
{
    return $test->actingAs($staff, 'admin')->from(route('admin.referrals.commissions.show', $commission))
        ->post(cmxActionUrl($commission, $type), cmxActionForm($commission, $type, $staff, $overrides));
}

describe('status', function () {
    it('derives the status from the single action: Credited until it is Reversed or Cancelled, with no status column and the commission row unchanged', function (CommissionActionType $type) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        $row = $commission->fresh()->getAttributes();
        expect($commission->fresh()->status())->toBe(CommissionStatus::Credited)
            ->and(Schema::hasColumn('commissions', 'status'))->toBeFalse()
            ->and(Schema::hasColumn('commission_actions', 'status'))->toBeFalse();

        catAct($this, $commission, $type, $staff)->assertRedirect(route('admin.referrals.commissions.show', $commission))->assertSessionHasNoErrors();

        expect($commission->fresh()->status())->toBe($type->status())
            ->and($commission->fresh()->status())->toBe($type === CommissionActionType::Reversal ? CommissionStatus::Reversed : CommissionStatus::Cancelled)
            ->and($commission->fresh()->getAttributes())->toBe($row);
    })->with('cat actions');
});

describe('reversal', function () {
    it('debits the full amount as a separate "Commission reversal", linked to its action, leaving the commission and its credit as they were', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        $credit = Transaction::findOrFail($commission->credit_transaction_id)->getAttributes();
        $creditEntry = WalletLedgerEntry::where('transaction_id', $commission->credit_transaction_id)->sole()->getAttributes();
        $row = $commission->fresh()->getAttributes();
        $form = cmxActionForm($commission, CommissionActionType::Reversal, $staff, ['reason' => 'Purchase found to be a duplicate by support.']);

        $this->actingAs($staff, 'admin')->post(cmxActionUrl($commission, CommissionActionType::Reversal), $form)
            ->assertRedirect(route('admin.referrals.commissions.show', $commission))->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status) => str_starts_with($status, 'Commission reversed: ₦12.50 was debited'));

        $action = CommissionAction::sole();
        $debit = Transaction::findOrFail($action->reversal_transaction_id);
        $wallet = Wallet::where('user_id', $referrer->id)->sole();
        expect($action->only(['commission_id', 'wallet_id', 'type', 'reason', 'acted_by']))->toBe(['commission_id' => $commission->id,
            'wallet_id' => $wallet->id, 'type' => CommissionActionType::Reversal, 'reason' => 'Purchase found to be a duplicate by support.', 'acted_by' => $staff->id])
            ->and($action->reference)->toMatch('/\ACMA-[0-9A-Z]{26}\z/')
            ->and($action->idempotency_key)->toBe(CommissionActionToken::open($form['token'], $commission, CommissionActionType::Reversal, $staff))
            ->and($debit->only(['user_id', 'wallet_id', 'type', 'direction', 'amount_kobo', 'status', 'description', 'idempotency_key', 'created_by', 'metadata']))
            ->toBe(['user_id' => $referrer->id, 'wallet_id' => $wallet->id, 'type' => TransactionType::Commission, 'direction' => Direction::Debit,
                'amount_kobo' => 1_250, 'status' => TransactionStatus::Successful, 'description' => 'Commission reversal',
                'idempotency_key' => 'commission-reversal:'.$commission->reference, 'created_by' => $staff->id, 'metadata' => null])
            ->and(WalletLedgerEntry::where('transaction_id', $debit->id)->sole()->only(['wallet_id', 'direction', 'amount_kobo', 'balance_after_kobo',
                'entry_type', 'description', 'reverses_entry_id', 'metadata']))
            ->toBe(['wallet_id' => $wallet->id, 'direction' => Direction::Debit, 'amount_kobo' => 1_250, 'balance_after_kobo' => 5_000,
                'entry_type' => LedgerEntryType::CommissionReversal, 'description' => 'Commission reversal', 'reverses_entry_id' => null, 'metadata' => null])
            ->and($debit->id)->not->toBe($commission->credit_transaction_id)
            ->and(Transaction::findOrFail($commission->credit_transaction_id)->getAttributes())->toBe($credit) // the original credit is untouched
            ->and(WalletLedgerEntry::where('transaction_id', $commission->credit_transaction_id)->sole()->getAttributes())->toBe($creditEntry)
            ->and($commission->fresh()->getAttributes())->toBe($row)
            ->and($commission->fresh()->amount_kobo)->toBe(1_250)
            ->and($wallet->balance_kobo)->toBe(5_000);
        cmxClean();
    });

    it('may take the wallet down to exactly zero, never below', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(); // the wallet holds only this commission

        catAct($this, $commission, CommissionActionType::Reversal, $staff)->assertSessionHasNoErrors();

        expect(cmxBalance($referrer))->toBe(0)->and($commission->fresh()->status())->toBe(CommissionStatus::Reversed);
        cmxClean();
    });

    it('accepts a reason of exactly 10 and of exactly 500 characters', function (int $length) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);

        catAct($this, $commission, CommissionActionType::Reversal, $staff, ['reason' => str_repeat('é', $length)])->assertSessionHasNoErrors();

        expect(mb_strlen(CommissionAction::sole()->reason))->toBe($length);
    })->with([10, 500]);
});

describe('cancellation', function () {
    it('records the cancellation and moves no money, also on a frozen wallet', function (bool $frozen) {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        if ($frozen) {
            app(SetWalletStatus::class)->handle($referrer, WalletStatus::Frozen, $staff);
        }
        $money = [Transaction::count(), WalletLedgerEntry::count(), cmxBalance($referrer)];
        $row = $commission->fresh()->getAttributes();

        catAct($this, $commission, CommissionActionType::Cancellation, $staff, ['reason' => 'Referral found to be the same household.'])
            ->assertRedirect(route('admin.referrals.commissions.show', $commission))->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Commission cancelled. No money was moved.');

        expect(CommissionAction::sole()->only(['commission_id', 'type', 'reversal_transaction_id', 'reason', 'acted_by']))->toBe([
            'commission_id' => $commission->id, 'type' => CommissionActionType::Cancellation, 'reversal_transaction_id' => null,
            'reason' => 'Referral found to be the same household.', 'acted_by' => $staff->id])
            ->and([Transaction::count(), WalletLedgerEntry::count(), cmxBalance($referrer)])->toBe($money)
            ->and($commission->fresh()->getAttributes())->toBe($row)
            ->and($commission->fresh()->status())->toBe(CommissionStatus::Cancelled);
        cmxClean();
    })->with(['an active wallet' => false, 'a frozen wallet' => true]);
});

describe('refusals write nothing', function () {
    it('allows one action ever: a reversed or cancelled commission can be neither reversed nor cancelled', function (CommissionActionType $first, CommissionActionType $second, string $message) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        catAct($this, $commission, $first, $staff)->assertSessionHasNoErrors();
        $rows = catRows();

        catAct($this, $commission, $second, $staff)->assertRedirect(route('admin.referrals.commissions.show', $commission))
            ->assertSessionHasErrorsIn($second->value, ['action' => $message]);

        expect(catRows())->toBe($rows)->and(CommissionAction::count())->toBe(1);
    })->with([
        'reverse after a reversal' => [CommissionActionType::Reversal, CommissionActionType::Reversal, 'This commission was already reversed. A commission can have only one action, ever.'],
        'cancel after a reversal' => [CommissionActionType::Reversal, CommissionActionType::Cancellation, 'This commission was already reversed. A commission can have only one action, ever.'],
        'cancel after a cancellation' => [CommissionActionType::Cancellation, CommissionActionType::Cancellation, 'This commission was already cancelled. A commission can have only one action, ever.'],
        'reverse after a cancellation' => [CommissionActionType::Cancellation, CommissionActionType::Reversal, 'This commission was already cancelled. A commission can have only one action, ever.'],
    ]);

    it('refuses to reverse while the referrer\'s wallet is frozen, and lets the commission be cancelled instead', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        app(SetWalletStatus::class)->handle($referrer, WalletStatus::Frozen, $staff);
        $rows = catRows();

        catAct($this, $commission, CommissionActionType::Reversal, $staff)->assertSessionHasErrorsIn('reversal', ['action' => ActOnCommission::FROZEN]);
        expect(catRows())->toBe($rows);

        catAct($this, $commission, CommissionActionType::Cancellation, $staff)->assertSessionHasNoErrors();
        expect($commission->fresh()->status())->toBe(CommissionStatus::Cancelled)->and(cmxBalance($referrer))->toBe(6_250);
    });

    it('refuses to reverse when the wallet holds less than the commission: there is no partial reversal', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(); // 1,250 kobo
        $wallets = app(WalletService::class);
        $wallets->debit($wallets->walletFor($referrer), 1, LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Test spending');
        $rows = catRows();

        catAct($this, $commission, CommissionActionType::Reversal, $staff)->assertSessionHasErrorsIn('reversal', ['action' => ActOnCommission::TOO_LOW]);

        expect(catRows())->toBe($rows)->and(cmxBalance($referrer))->toBe(1_249);
    });

    it('answers 404 for a commission that does not exist, writing nothing', function (CommissionActionType $type) {
        $staff = cmxStaff();
        cmxCommission(5_000);
        $rows = catRows();
        $this->actingAs($staff, 'admin');

        $route = $type === CommissionActionType::Reversal ? 'admin.referrals.commissions.reverse' : 'admin.referrals.commissions.cancel';
        $this->post(route($route, 'COM-'.str_repeat('Z', 26)), ['reason' => 'Taken back after a review.', 'confirm' => '1', 'token' => 'x'])->assertNotFound();
        $this->post('/admin/referrals/commissions/PUR-'.str_repeat('Z', 26).'/'.($type === CommissionActionType::Reversal ? 'reverse' : 'cancel'))->assertNotFound();
        $this->get(route('admin.referrals.commissions.show', 'COM-'.str_repeat('Z', 26)))->assertNotFound();

        expect(catRows())->toBe($rows);
    })->with('cat actions');

    it('refuses a missing, short or long reason, a missing confirmation, and a missing or broken token', function (array $overrides, string $field, string $message, CommissionActionType $type) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        $rows = catRows();

        catAct($this, $commission, $type, $staff, $overrides)->assertRedirect(route('admin.referrals.commissions.show', $commission))
            ->assertSessionHasErrorsIn($type->value, [$field => $message]);

        expect(catRows())->toBe($rows);
    })->with([
        'no reason' => [['reason' => ''], 'reason', 'Give the reason for this action.'],
        'a reason of 9 characters' => [['reason' => str_repeat('a', 9)], 'reason', 'Give a reason of at least 10 characters (kept permanently, shown to staff only).'],
        'a reason of 501 characters' => [['reason' => str_repeat('a', 501)], 'reason', 'Keep the reason to 500 characters or fewer.'],
        'no confirmation' => [['confirm' => ''], 'confirm', 'Confirm this action before submitting it.'],
        'no token' => [['token' => ''], 'token', ActOnCommission::INVALID_FORM],
        'a broken token' => [['token' => 'not-a-token'], 'action', ActOnCommission::INVALID_FORM],
    ])->with('cat actions');
});

describe('one-time tokens', function () {
    it('accepts each form once: sent again, its token is refused as already recorded', function (CommissionActionType $type) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        $form = cmxActionForm($commission, $type, $staff);
        $this->actingAs($staff, 'admin')->from(route('admin.referrals.commissions.show', $commission));
        $this->post(cmxActionUrl($commission, $type), $form)->assertSessionHasNoErrors();
        $rows = catRows();

        $this->post(cmxActionUrl($commission, $type), $form)
            ->assertSessionHasErrorsIn($type->value, ['action' => 'This '.strtolower($type->label()).' was already recorded with this form. Nothing more was changed.']);

        expect(catRows())->toBe($rows)
            ->and(CommissionAction::count())->toBe(1)
            ->and(Transaction::where('type', TransactionType::Commission->value)->where('direction', Direction::Debit->value)->count())
            ->toBe($type === CommissionActionType::Reversal ? 1 : 0);
    })->with('cat actions');

    it('refuses a token issued for the other action, another commission or another staff member, an altered one, or one that has expired', function (Closure $token, CommissionActionType $type) {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        [, $other] = cmxCommission(0, $referrer);
        $rows = catRows();

        catAct($this, $commission, $type, $staff, ['token' => $token($commission, $other, $type, $staff, $this)])
            ->assertSessionHasErrorsIn($type->value, ['action' => ActOnCommission::INVALID_FORM]);

        expect(catRows())->toBe($rows);
    })->with([
        'the other action\'s token' => [fn (Commission $c, Commission $o, CommissionActionType $t, SystemUser $s) => CommissionActionToken::issue($c,
            $t === CommissionActionType::Reversal ? CommissionActionType::Cancellation : CommissionActionType::Reversal, $s)],
        'another commission\'s token' => [fn (Commission $c, Commission $o, CommissionActionType $t, SystemUser $s) => CommissionActionToken::issue($o, $t, $s)],
        'another staff member\'s token' => [fn (Commission $c, Commission $o, CommissionActionType $t, SystemUser $s) => CommissionActionToken::issue($c, $t, cmxStaff())],
        'an altered token' => [function (Commission $c, Commission $o, CommissionActionType $t, SystemUser $s) {
            $token = CommissionActionToken::issue($c, $t, $s);

            return substr($token, 0, -4).(substr($token, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
        }],
        'an expired token (30 minutes)' => [function (Commission $c, Commission $o, CommissionActionType $t, SystemUser $s, $test) {
            $token = CommissionActionToken::issue($c, $t, $s);
            $test->travel(CommissionActionToken::VALID_SECONDS)->seconds();

            return $token;
        }],
        'an encrypted token in another shape' => [fn (Commission $c, Commission $o, CommissionActionType $t, SystemUser $s) => encrypt(['commission' => $c->id,
            'action' => $t->value, 'staff' => $s->id, 'key' => (string) Str::uuid(), 'issued_at' => now()->getTimestamp()])],
    ])->with('cat actions');

    it('keeps a used reversal token from cancelling, and a used cancellation token from reversing, the same commission', function (CommissionActionType $type) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        $form = cmxActionForm($commission, $type, $staff);
        $this->actingAs($staff, 'admin')->post(cmxActionUrl($commission, $type), $form)->assertSessionHasNoErrors();
        $other = $type === CommissionActionType::Reversal ? CommissionActionType::Cancellation : CommissionActionType::Reversal;
        $rows = catRows();

        $this->post(cmxActionUrl($commission, $other), $form)->assertSessionHasErrorsIn($other->value, ['action' => ActOnCommission::INVALID_FORM]);

        expect(catRows())->toBe($rows);
    })->with('cat actions');
});

describe('immutability', function () {
    it('keeps the commission, its credit and its action unchangeable and undeletable', function (CommissionActionType $type) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        catAct($this, $commission, $type, $staff)->assertSessionHasNoErrors();
        $other = $type === CommissionActionType::Reversal ? CommissionActionType::Cancellation : CommissionActionType::Reversal;
        $rows = catRows();

        expect(fn () => Commission::sole()->forceFill(['amount_kobo' => 1])->save())->toThrow(LogicException::class, 'Commissions never change')
            ->and(fn () => Commission::sole()->delete())->toThrow(LogicException::class, 'Commissions are never deleted.')
            ->and(fn () => CommissionAction::sole()->forceFill(['reason' => 'Another reason entirely.'])->save())->toThrow(LogicException::class, 'Commission actions never change.')
            ->and(fn () => CommissionAction::sole()->forceFill(['type' => $other])->save())->toThrow(LogicException::class, 'Commission actions never change.')
            ->and(fn () => CommissionAction::sole()->delete())->toThrow(LogicException::class, 'Commission actions are never deleted.')
            ->and(fn () => Transaction::findOrFail($commission->credit_transaction_id)->forceFill(['amount_kobo' => 1])->save())->toThrow(LogicException::class)
            ->and(fn () => WalletLedgerEntry::where('transaction_id', $commission->credit_transaction_id)->sole()->delete())->toThrow(LogicException::class)
            ->and(catRows())->toBe($rows);
    })->with('cat actions');

    it('keeps the wallet\'s own reversal tool away from the commission credit and the reversal debit', function () {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        catAct($this, $commission, CommissionActionType::Reversal, $staff)->assertSessionHasNoErrors();
        $rows = catRows();

        foreach ([$commission->credit_transaction_id, CommissionAction::sole()->reversal_transaction_id] as $id) {
            $this->post("/admin/wallet/{$referrer->id}/transactions/{$id}/reverse", ['reversal_reason' => 'Trying to undo a commission here.',
                'reversal_confirm' => '1', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors(['reversal' => 'Only manual adjustments can be reversed here.']);
            expect(fn () => app(ReverseAdjustment::class)->handle(Transaction::findOrFail($id), 'Trying to undo a commission here.', (string) Str::uuid(), $staff))
                ->toThrow(InvalidTransactionState::class, 'Only manual adjustments can be reversed here.');
        }

        expect(catRows())->toBe($rows);
    });

    it('offers no way to change or delete a commission or an action, and writes actions only through their guarded model', function () {
        $routes = collect(Route::getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'commissions'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->values()->all();
        $action = File::get(app_path('Actions/Admin/Referrals/ActOnCommission.php'));

        expect($routes)->toBe(['GET|HEAD admin/referrals/commissions/{commission}', 'POST admin/referrals/commissions/{commission}/reverse',
            'POST admin/referrals/commissions/{commission}/cancel'])
            ->and($action)->toContain('(new CommissionAction)->forceFill(')->toContain('$this->wallets->debit(')
            ->not->toContain('DB::table(')->not->toContain('DB::statement(')->not->toContain('DB::update(')->not->toContain('DB::delete(')
            ->not->toContain('balance_kobo\' =>') // money moves through WalletService only
            // The bulk-write source scan (T7, ReferralFoundationTest) reads every file under app/, these included.
            ->and(collect(File::allFiles(app_path()))->map(fn ($file) => $file->getPathname())->all())
            ->toContain(app_path('Actions/Admin/Referrals/ActOnCommission.php'), app_path('Http/Controllers/Admin/CommissionController.php'));
    });
});

describe('failure safety', function () {
    it('writes nothing when something fails during the action: the debit and the action are one transaction', function (Closure $break, CommissionActionType $type) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        $token = CommissionActionToken::issue($commission, $type, $staff);
        $rows = catRows();
        $break();

        expect(fn () => app(ActOnCommission::class)->handle($commission, $type, 'Taken back after a review of the purchase.', $token, $staff))
            ->toThrow(RuntimeException::class, 'Unexpected test failure')
            ->and(catRows())->toBe($rows) // no action without its debit, and no debit without its action
            ->and($commission->fresh()->status())->toBe(CommissionStatus::Credited);
    })->with([
        'writing the action' => [fn () => CommissionAction::creating(fn () => throw new RuntimeException('Unexpected test failure'))],
    ])->with('cat actions');

    it('writes nothing when the reversal fails while posting its debit', function (Closure $break) {
        $staff = cmxStaff();
        [, $commission] = cmxCommission(5_000);
        $token = CommissionActionToken::issue($commission, CommissionActionType::Reversal, $staff);
        $rows = catRows();
        $break();

        expect(fn () => app(ActOnCommission::class)->handle($commission, CommissionActionType::Reversal, 'Taken back after a review of the purchase.', $token, $staff))
            ->toThrow(RuntimeException::class, 'Unexpected test failure')
            ->and(catRows())->toBe($rows);
    })->with([
        'writing its ledger entry' => [fn () => WalletLedgerEntry::creating(fn () => throw new RuntimeException('Unexpected test failure'))],
        'updating the wallet balance' => [fn () => Wallet::updating(fn () => throw new RuntimeException('Unexpected test failure'))],
    ]);

    it('refuses in the action itself: inactive or unauthorized staff, a short reason, or no valid token', function (Closure $call, string $exception) {
        [, $commission] = cmxCommission(5_000);
        $rows = catRows();

        expect(fn () => $call($commission))->toThrow($exception)->and(catRows())->toBe($rows);
    })->with([
        'disabled staff' => [function (Commission $c) {
            $staff = cmxStaff();
            $staff->forceFill(['status' => UserStatus::Disabled])->save();

            return app(ActOnCommission::class)->handle($c, CommissionActionType::Reversal, 'Taken back after a review.', CommissionActionToken::issue($c, CommissionActionType::Reversal, $staff), $staff);
        }, AuthorizationException::class],
        'staff with referrals.view only' => [function (Commission $c) {
            $staff = cmxStaffWith(['admin.access', 'referrals.view']);

            return app(ActOnCommission::class)->handle($c, CommissionActionType::Cancellation, 'Voided after a review.', CommissionActionToken::issue($c, CommissionActionType::Cancellation, $staff), $staff);
        }, AuthorizationException::class],
        'staff with referrals.manage but not referrals.view' => [function (Commission $c) {
            $staff = cmxStaffWith(['admin.access', 'referrals.manage']);

            return app(ActOnCommission::class)->handle($c, CommissionActionType::Cancellation, 'Voided after a review.', CommissionActionToken::issue($c, CommissionActionType::Cancellation, $staff), $staff);
        }, AuthorizationException::class],
        'a reason of 9 characters' => [function (Commission $c) {
            $staff = cmxStaff();

            return app(ActOnCommission::class)->handle($c, CommissionActionType::Cancellation, 'Too short', CommissionActionToken::issue($c, CommissionActionType::Cancellation, $staff), $staff);
        }, InvalidArgumentException::class],
        'no token' => [fn (Commission $c) => app(ActOnCommission::class)->handle($c, CommissionActionType::Reversal, 'Taken back after a review.', null, cmxStaff()),
            ValidationException::class],
    ]);
});

describe('customer privacy', function () {
    it('shows the referrer only a separate "Commission reversal" in their wallet: no reason, staff, buyer, purchase or commission reference', function () {
        $staff = cmxStaff();
        $staff->forceFill(['name' => 'Staffer Qzxcpfive'])->save(); // names no factory generates
        [$referrer, $commission] = cmxCommission(5_000);
        $commission->purchase->user->forceFill(['name' => 'Buyer Wqvcpfive', 'email' => 'buyer.wqvcpfive@example.com'])->save();
        catAct($this, $commission, CommissionActionType::Reversal, $staff, ['reason' => 'Fraud review: purchase disputed by the bank.'])->assertSessionHasNoErrors();

        $html = $this->actingAs($referrer, 'web')->get('/wallet')->assertOk()->assertSee('Referral commission')->assertSee('Commission reversal')->getContent();

        expect($html)->not->toContain('Fraud review')->not->toContain('Qzxcpfive')->not->toContain('Wqvcpfive')->not->toContain('buyer.wqvcpfive')
            ->not->toContain($commission->reference)->not->toContain(CommissionAction::sole()->reference)
            ->not->toContain($commission->purchase->reference)->not->toContain('08012345678');
    });

    it('leaves reversed and cancelled commissions out of the referrer\'s earned totals, and shows them nothing about the actions', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        [, $reversed] = cmxCommission(0, $referrer);
        [, $cancelled] = cmxCommission(0, $referrer);
        cmxCommission(0, $referrer);
        catAct($this, $reversed, CommissionActionType::Reversal, $staff, ['reason' => 'Fraud review: reversed by staff.'])->assertSessionHasNoErrors();
        catAct($this, $cancelled, CommissionActionType::Cancellation, $staff, ['reason' => 'Household review: cancelled by staff.'])->assertSessionHasNoErrors();

        expect(app(ReferralSummary::class)->figures($referrer))->toMatchArray(['earned_kobo' => 1_250, 'this_month_kobo' => 1_250])
            ->and(cmxBalance($referrer))->toBe(2_500); // the cancelled one stays in the wallet, the reversed one was taken back
        $html = $this->actingAs($referrer, 'web')->get('/referrals')->assertOk()->getContent();
        expect($html)->not->toContain('Fraud review')->not->toContain('Household review')->not->toContain('CMA-')->not->toContain('COM-');
        cmxClean();
    });
});

describe('commissions:verify', function () {
    it('finds reversals and cancellations consistent', function () {
        $staff = cmxStaff();
        $referrer = cmxReferrer();
        [, $reversed] = cmxCommission(0, $referrer);
        [, $cancelled] = cmxCommission(0, $referrer);
        catAct($this, $reversed, CommissionActionType::Reversal, $staff)->assertSessionHasNoErrors();
        catAct($this, $cancelled, CommissionActionType::Cancellation, $staff)->assertSessionHasNoErrors();

        cmxClean();
    });

    it('reports a reversal debit without its reversal, a changed reversal debit, a cancellation with money, and a damaged action, changing nothing', function (Closure $break, string $expected) {
        $staff = cmxStaff();
        [$referrer, $commission] = cmxCommission(5_000);
        [, $other] = cmxCommission(0, $referrer);
        catAct($this, $commission, CommissionActionType::Reversal, $staff)->assertSessionHasNoErrors();
        catAct($this, $other, CommissionActionType::Cancellation, $staff)->assertSessionHasNoErrors();
        $break($referrer, CommissionAction::where('type', 'reversal')->sole(), CommissionAction::where('type', 'cancellation')->sole());
        $rows = catRows();

        $code = Artisan::call('commissions:verify');
        $output = Artisan::output();

        expect($code)->toBe(1)->and($output)->toContain($expected)->and($output)->toContain('Nothing was changed')->and(catRows())->toBe($rows);
    })->with([
        'a reversal debit that belongs to no reversal' => [function ($referrer) {
            $wallets = app(WalletService::class);
            $wallets->debit($wallets->walletFor($referrer), 100, LedgerEntryType::CommissionReversal, TransactionType::Commission, 'Commission reversal', 'test:'.Str::uuid());
        }, 'a commission reversal debit that belongs to no reversal.'],
        'a reversal debit of another amount' => [fn ($referrer, CommissionAction $reversal) => DB::table('transactions')->where('id', $reversal->reversal_transaction_id)
            ->update(['amount_kobo' => 1]), 'is a reversal without one separate, successful commission reversal debit of the commission amount'],
        'a cancellation with a wallet transaction' => [function ($referrer, CommissionAction $reversal, CommissionAction $cancellation) {
            $wallets = app(WalletService::class);
            $debit = $wallets->debit($wallets->walletFor($referrer), 1_250, LedgerEntryType::CommissionReversal, TransactionType::Commission, 'Commission reversal', 'test:'.Str::uuid());
            DB::table('commission_actions')->where('id', $cancellation->id)->update(['reversal_transaction_id' => $debit->transaction->id]);
        }, 'is a cancellation with a wallet transaction, but a cancellation moves no money.'],
        'an action reason of 5 characters' => [fn ($referrer, CommissionAction $reversal) => DB::table('commission_actions')->where('id', $reversal->id)
            ->update(['reason' => 'short']), 'has no reason of 10 to 500 characters.'],
        'an action of an unknown type' => [fn ($referrer, CommissionAction $reversal, CommissionAction $cancellation) => DB::table('commission_actions')
            ->where('id', $cancellation->id)->update(['type' => 'refund']), 'is neither a reversal nor a cancellation.'],
    ]);
});
