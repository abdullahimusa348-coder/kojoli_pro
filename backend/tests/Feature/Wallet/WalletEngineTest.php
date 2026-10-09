<?php

use App\Exceptions\Wallet\AlreadyReversed;
use App\Exceptions\Wallet\BalanceLimitExceeded;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Exceptions\Wallet\InsufficientFunds;
use App\Exceptions\Wallet\InvalidAmount;
use App\Exceptions\Wallet\InvalidTransactionState;
use App\Exceptions\Wallet\WalletFrozen;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Wallet\WalletService;
use App\Support\Enums\UserType;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use App\Support\Wallet\WalletType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function wallets(): WalletService
{
    return app(WalletService::class);
}

function weCredit(Wallet $wallet, int $kobo, ?string $key = null)
{
    return wallets()->credit($wallet, $kobo, LedgerEntryType::AdjustmentCredit, TransactionType::Adjustment, 'Balance adjustment (credit)', $key);
}

function weDebit(Wallet $wallet, int $kobo, ?string $key = null)
{
    return wallets()->debit($wallet, $kobo, LedgerEntryType::AdjustmentDebit, TransactionType::Adjustment, 'Balance adjustment (debit)', $key);
}

function weWallet(array $user = []): Wallet
{
    return wallets()->walletFor(User::factory()->create($user));
}

describe('schema', function () {
    it('creates the wallet tables with integer kobo columns', function () {
        expect(Schema::hasColumns('wallets', ['id', 'user_id', 'type', 'currency', 'balance_kobo', 'status', 'created_at', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumns('transactions', ['reference', 'user_id', 'wallet_id', 'type', 'direction', 'amount_kobo', 'status', 'idempotency_key', 'description', 'created_by', 'metadata', 'completed_at']))->toBeTrue()
            ->and(Schema::hasColumns('wallet_ledger_entries', ['reference', 'wallet_id', 'transaction_id', 'direction', 'amount_kobo', 'balance_after_kobo', 'entry_type', 'reverses_entry_id', 'description', 'created_by', 'metadata', 'created_at']))->toBeTrue()
            ->and(Schema::hasColumn('wallet_ledger_entries', 'updated_at'))->toBeFalse();
    });

    it('adds no money columns to users, catalog, pricing or provider tables', function () {
        foreach (['users', 'plans', 'plan_prices', 'providers', 'plan_provider_routes'] as $table) {
            foreach (['balance', 'balance_kobo', 'wallet_id', 'wallet_balance', 'transaction_id'] as $column) {
                expect(Schema::hasColumn($table, $column))->toBeFalse("{$table}.{$column} exists");
            }
        }
        // payments exists since Phase 9 and never holds balances (it links to one funding transaction).
        // purchases exists since Phase 10: it links to wallet transactions and never holds a balance.
        foreach (['provider_attempts', 'withdrawals', 'deposits', 'wallet_holds'] as $table) {
            expect(Schema::hasTable($table))->toBeFalse("{$table} exists");
        }
    });

    it('enforces one wallet per customer per type and unique references and keys', function () {
        $wallet = weWallet();
        $tx = weCredit($wallet, 100, 'key-1')->transaction;

        expect(fn () => DB::table('wallets')->insert(['user_id' => $wallet->user_id, 'type' => 'main', 'currency' => 'NGN', 'balance_kobo' => 0, 'status' => 'active']))
            ->toThrow(QueryException::class)
            ->and(fn () => DB::table('transactions')->insert(['reference' => $tx->reference, 'user_id' => $wallet->user_id, 'wallet_id' => $wallet->id, 'type' => 'adjustment',
                'direction' => 'credit', 'amount_kobo' => 1, 'status' => 'successful', 'description' => 'x']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('transactions')->insert(['reference' => 'TXN-OTHER', 'user_id' => $wallet->user_id, 'wallet_id' => $wallet->id, 'type' => 'adjustment',
                'direction' => 'credit', 'amount_kobo' => 1, 'status' => 'successful', 'idempotency_key' => 'key-1', 'description' => 'x']))->toThrow(QueryException::class);
    });
});

describe('wallets', function () {
    it('creates a main NGN wallet on first use and reuses it', function () {
        $user = User::factory()->create();

        $wallet = wallets()->walletFor($user);
        $again = wallets()->walletFor($user);

        expect($wallet->type)->toBe(WalletType::Main)->and($wallet->currency)->toBe('NGN')->and($wallet->balance_kobo)->toBe(0)
            ->and($wallet->status)->toBe(WalletStatus::Active)->and($again->id)->toBe($wallet->id)->and(Wallet::count())->toBe(1);
    });

    it('uses the same wallet engine for every customer type', function (UserType $type) {
        $wallet = weWallet(['user_type' => $type]);
        weCredit($wallet, 5_000);

        expect($wallet->fresh()->balance_kobo)->toBe(5_000);
    })->with(UserType::cases());

    it('recovers when a parallel request already created the wallet', function () {
        $user = User::factory()->create();
        DB::table('wallets')->insert(['user_id' => $user->id, 'type' => 'main', 'currency' => 'NGN', 'balance_kobo' => 0, 'status' => 'active']);

        expect(wallets()->walletFor($user)->user_id)->toBe($user->id)->and(Wallet::count())->toBe(1);
    });
});

describe('credits and debits', function () {
    it('credits and debits with integer kobo, writing transaction, entry and balance together', function () {
        $wallet = weWallet();

        $credit = weCredit($wallet, 150_075);
        $debit = weDebit($wallet, 50_025);

        $wallet->refresh();
        expect($wallet->balance_kobo)->toBe(100_050)->toBeInt()
            ->and($credit->transaction->status)->toBe(TransactionStatus::Successful)->and($credit->transaction->completed_at)->not->toBeNull()
            ->and($credit->transaction->reference)->toStartWith('TXN-')->and($credit->entry->reference)->toStartWith('WLE-')
            ->and($credit->entry->balance_after_kobo)->toBe(150_075)->and($debit->entry->balance_after_kobo)->toBe(100_050)
            ->and($debit->entry->direction)->toBe(Direction::Debit)->and($debit->entry->transaction_id)->toBe($debit->transaction->id)
            ->and(Transaction::count())->toBe(2)->and(WalletLedgerEntry::count())->toBe(2)
            ->and($credit->replayed)->toBeFalse();
    });

    it('refuses overdrafts and writes nothing', function () {
        $wallet = weWallet();
        weCredit($wallet, 1_000);

        expect(fn () => weDebit($wallet, 1_001))->toThrow(InsufficientFunds::class)
            ->and($wallet->fresh()->balance_kobo)->toBe(1_000)->and(Transaction::count())->toBe(1)->and(WalletLedgerEntry::count())->toBe(1);

        weDebit($wallet, 1_000);
        expect($wallet->fresh()->balance_kobo)->toBe(0)
            ->and(fn () => weDebit($wallet, 1))->toThrow(InsufficientFunds::class);
    });

    it('rejects invalid amounts', function (int $amount) {
        $wallet = weWallet();

        expect(fn () => weCredit($wallet, $amount))->toThrow(InvalidAmount::class)
            ->and(fn () => weDebit($wallet, $amount))->toThrow(InvalidAmount::class)
            ->and(Transaction::count())->toBe(0);
    })->with([0, -1, -500, WalletService::MAX_BALANCE_KOBO + 1, PHP_INT_MAX]);

    it('refuses credits above the maximum balance', function () {
        $wallet = weWallet();
        weCredit($wallet, WalletService::MAX_BALANCE_KOBO - 10);

        expect(fn () => weCredit($wallet, 11))->toThrow(BalanceLimitExceeded::class)
            ->and($wallet->fresh()->balance_kobo)->toBe(WalletService::MAX_BALANCE_KOBO - 10);
        weCredit($wallet, 10);
        expect($wallet->fresh()->balance_kobo)->toBe(WalletService::MAX_BALANCE_KOBO);
    });

    it('blocks debits but allows credits on a frozen wallet', function () {
        $wallet = weWallet();
        weCredit($wallet, 5_000);
        wallets()->setStatus($wallet, WalletStatus::Frozen);

        expect(fn () => weDebit($wallet, 100))->toThrow(WalletFrozen::class);
        weCredit($wallet, 100);
        expect($wallet->fresh()->balance_kobo)->toBe(5_100);

        wallets()->setStatus($wallet, WalletStatus::Active);
        weDebit($wallet, 100);
        expect($wallet->fresh()->balance_kobo)->toBe(5_000);
    });
});

describe('idempotency', function () {
    it('returns the original result for a repeated key without posting again', function () {
        $wallet = weWallet();
        weCredit($wallet, 10_000);

        $first = weDebit($wallet, 2_500, 'order-123');
        $second = weDebit($wallet, 2_500, 'order-123');

        expect($second->replayed)->toBeTrue()->and($second->transaction->id)->toBe($first->transaction->id)
            ->and($second->entry->id)->toBe($first->entry->id)
            ->and($wallet->fresh()->balance_kobo)->toBe(7_500)->and(WalletLedgerEntry::count())->toBe(2);
    });

    it('refuses a key reused with different parameters', function (Closure $retry) {
        $wallet = weWallet();
        weCredit($wallet, 10_000);
        weDebit($wallet, 2_500, 'order-123');

        expect(fn () => $retry($wallet))->toThrow(IdempotencyConflict::class)
            ->and($wallet->fresh()->balance_kobo)->toBe(7_500)->and(WalletLedgerEntry::count())->toBe(2);
    })->with([
        'different amount' => [fn (Wallet $w) => weDebit($w, 2_600, 'order-123')],
        'different direction' => [fn (Wallet $w) => weCredit($w, 2_500, 'order-123')],
    ]);

    it('scopes keys per customer', function () {
        $a = weWallet();
        $b = weWallet();

        weCredit($a, 100, 'same-key');
        weCredit($b, 100, 'same-key');

        expect($a->fresh()->balance_kobo)->toBe(100)->and($b->fresh()->balance_kobo)->toBe(100)->and(Transaction::count())->toBe(2);
    });
});

describe('reversals', function () {
    it('reverses with one compensating entry and marks the transaction reversed', function () {
        $wallet = weWallet();
        $credit = weCredit($wallet, 4_000);

        $reversal = wallets()->reverse($credit->transaction, 'Credited the wrong customer');

        $tx = $credit->transaction->fresh();
        expect($tx->status)->toBe(TransactionStatus::Reversed)
            ->and($reversal->entry->entry_type)->toBe(LedgerEntryType::Reversal)->and($reversal->entry->direction)->toBe(Direction::Debit)
            ->and($reversal->entry->reverses_entry_id)->toBe($credit->entry->id)->and($reversal->entry->transaction_id)->toBe($tx->id)
            ->and($reversal->entry->amount_kobo)->toBe(4_000)->and($reversal->entry->balance_after_kobo)->toBe(0)
            ->and($wallet->fresh()->balance_kobo)->toBe(0)
            ->and($credit->entry->fresh()->amount_kobo)->toBe(4_000)
            ->and(WalletLedgerEntry::count())->toBe(2);
    });

    it('can reverse only once; the same key returns the first reversal', function () {
        $wallet = weWallet();
        $credit = weCredit($wallet, 4_000);
        wallets()->reverse($credit->transaction, 'First reversal reason', null, 'rev-1');

        $again = wallets()->reverse($credit->transaction, 'First reversal reason', null, 'rev-1');
        expect($again->replayed)->toBeTrue()
            ->and(fn () => wallets()->reverse($credit->transaction, 'Second attempt reason', null, 'rev-2'))->toThrow(AlreadyReversed::class)
            ->and(fn () => wallets()->reverse($credit->transaction, 'Third attempt reason'))->toThrow(AlreadyReversed::class)
            ->and(WalletLedgerEntry::where('entry_type', 'reversal')->count())->toBe(1)->and($wallet->fresh()->balance_kobo)->toBe(0);
        expect(fn () => DB::table('wallet_ledger_entries')->insert(['reference' => 'WLE-X', 'wallet_id' => $wallet->id, 'transaction_id' => $credit->transaction->id,
            'direction' => 'debit', 'amount_kobo' => 1, 'balance_after_kobo' => 0, 'entry_type' => 'reversal', 'reverses_entry_id' => $credit->entry->id, 'description' => 'x']))
            ->toThrow(QueryException::class);
    });

    it('cannot reverse into an overdraft', function () {
        $wallet = weWallet();
        $credit = weCredit($wallet, 4_000);
        weDebit($wallet, 3_000);

        expect(fn () => wallets()->reverse($credit->transaction, 'Too late to reverse'))->toThrow(InsufficientFunds::class)
            ->and($credit->transaction->fresh()->status)->toBe(TransactionStatus::Successful)->and($wallet->fresh()->balance_kobo)->toBe(1_000);
    });

    it('reverses a debit with a compensating credit', function () {
        $wallet = weWallet();
        weCredit($wallet, 4_000);
        $debit = weDebit($wallet, 1_500);

        wallets()->reverse($debit->transaction, 'Debited by mistake');

        expect($wallet->fresh()->balance_kobo)->toBe(4_000)->and($debit->transaction->fresh()->status)->toBe(TransactionStatus::Reversed);
    });
});

describe('immutability and states', function () {
    it('never updates or deletes ledger entries', function () {
        $entry = weCredit(weWallet(), 100)->entry;

        expect(fn () => $entry->forceFill(['amount_kobo' => 999])->save())->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class)
            ->and($entry->fresh()->amount_kobo)->toBe(100);
    });

    it('keeps transaction money fields immutable and never deletes transactions', function () {
        $tx = weCredit(weWallet(), 100)->transaction;

        foreach (['amount_kobo' => 5, 'reference' => 'TXN-NEW', 'idempotency_key' => 'x', 'direction' => 'debit', 'wallet_id' => 999] as $field => $value) {
            expect(fn () => $tx->fresh()->forceFill([$field => $value])->save())->toThrow(LogicException::class);
        }
        expect(fn () => $tx->delete())->toThrow(LogicException::class)->and($tx->fresh()->amount_kobo)->toBe(100);
    });

    it('enforces valid status transitions', function () {
        expect(TransactionStatus::Pending->canTransitionTo(TransactionStatus::Successful))->toBeTrue()
            ->and(TransactionStatus::Pending->canTransitionTo(TransactionStatus::Failed))->toBeTrue()
            ->and(TransactionStatus::Successful->canTransitionTo(TransactionStatus::Reversed))->toBeTrue()
            ->and(TransactionStatus::Successful->canTransitionTo(TransactionStatus::Failed))->toBeFalse()
            ->and(TransactionStatus::Successful->canTransitionTo(TransactionStatus::Pending))->toBeFalse()
            ->and(TransactionStatus::Failed->canTransitionTo(TransactionStatus::Successful))->toBeFalse()
            ->and(TransactionStatus::Reversed->canTransitionTo(TransactionStatus::Successful))->toBeFalse();

        $tx = weCredit(weWallet(), 100)->transaction;
        expect(fn () => $tx->fresh()->forceFill(['status' => TransactionStatus::Failed])->save())->toThrow(InvalidTransactionState::class)
            ->and(fn () => $tx->fresh()->forceFill(['status' => TransactionStatus::Pending])->save())->toThrow(InvalidTransactionState::class);

        wallets()->reverse($tx, 'Reverse to test the states');
        expect(fn () => $tx->fresh()->forceFill(['status' => TransactionStatus::Successful])->save())->toThrow(InvalidTransactionState::class)
            ->and(fn () => wallets()->reverse($tx->fresh(), 'Again after reversal'))->toThrow(AlreadyReversed::class);
    });

    it('generates unique references', function () {
        $refs = collect(range(1, 200))->map(fn () => WalletService::reference('TXN'));

        expect($refs->unique()->count())->toBe(200)->and($refs->first())->toMatch('/^TXN-[0-9A-Z]{26}$/');
    });
});

describe('wallet:verify', function () {
    it('confirms consistent wallets', function () {
        $wallet = weWallet();
        weCredit($wallet, 10_000);
        weDebit($wallet, 2_500);
        weWallet();

        $this->artisan('wallet:verify')->expectsOutputToContain('All 2 wallet(s) are consistent')->assertSuccessful();
    });

    it('reports a corrupted cached balance and never repairs it', function () {
        $wallet = weWallet(['email' => 'broken@example.test']);
        weCredit($wallet, 10_000);
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_kobo' => 12_345]);

        $this->artisan('wallet:verify')
            ->expectsOutputToContain('cached balance ₦123.45 but ledger credits − debits = ₦100.00')
            ->expectsOutputToContain('Nothing was changed')
            ->assertFailed();

        expect($wallet->fresh()->balance_kobo)->toBe(12_345)->and(WalletLedgerEntry::count())->toBe(1);
    });

    it('reports a broken balance_after chain', function () {
        $wallet = weWallet();
        weCredit($wallet, 10_000);
        $entry = weCredit($wallet, 5_000)->entry;
        DB::table('wallet_ledger_entries')->where('id', $entry->id)->update(['balance_after_kobo' => 14_000]);
        DB::table('wallets')->where('id', $wallet->id)->update(['balance_kobo' => 14_000]);

        Artisan::call('wallet:verify');
        $output = Artisan::output();

        expect($output)->toContain("entry {$entry->reference} records balance after ₦140.00 but the ledger adds up to ₦150.00");
    });
});
