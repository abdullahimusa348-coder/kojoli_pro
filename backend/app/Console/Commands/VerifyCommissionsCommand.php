<?php

namespace App\Console\Commands;

use App\Models\Commission;
use App\Models\FailedCommissionAttempt;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\Service;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\Pricing\BasisPoints;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\CommissionFailureReason;
use App\Support\Referrals\QualifyingServices;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletType;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checks every referral commission against its purchase, the buyer's
 * referral link and its wallet credit, every failed commission attempt, and
 * the commission money totals (Phase 12), like purchases:verify does for
 * purchases. Reports only: it never credits, records, changes or removes
 * anything, so it is safe to run at any time and as often as needed. Output
 * names references and internal customer IDs only: never a name, email,
 * phone number or anything a purchase delivered.
 * - A commission belongs to a successful purchase of a qualifying service,
 *   goes to the buyer's own referrer, is the rate of the purchase amount,
 *   capped and rounded down (at least 1 kobo), on the referrer's Main
 *   Wallet, with its time of credit, and has one successful commission
 *   credit of its amount, posted for its purchase (one ledger entry); its
 *   purchase has no failed attempt.
 * - A failed attempt has a fixed reason code, belongs to a successful
 *   purchase, names the buyer's referrer, and its purchase has no commission.
 * - No commission credit belongs to no commission, and the commission
 *   credits add up to the commissions.
 */
class VerifyCommissionsCommand extends Command
{
    protected $signature = 'commissions:verify';

    protected $description = 'Check every referral commission and failed commission attempt against its purchase, referral and wallet credit (reports only, never repairs)';

    public function handle(): int
    {
        // One consistent snapshot: purchases, commissions and money keep moving while this runs.
        [$commissions, $attempts, $problems] = DB::transaction(fn () => $this->verify());
        $checked = "{$commissions} commission(s) and {$attempts} failed commission attempt(s)";

        if ($problems === []) {
            $this->info("All {$checked} are consistent with their purchases, referrals and wallet credits, and the commission totals match.");

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->error(count($problems)." problem(s) found in {$checked}. Nothing was changed; investigate before any correction.");

        return self::FAILURE;
    }

    /** @return array{0: int, 1: int, 2: list<string>} */
    private function verify(): array
    {
        $slugs = Service::query()->pluck('slug', 'id')->all();
        [$commissions, $attempts, $problems] = [0, 0, []];

        Commission::query()
            ->with(['purchase:id,reference,user_id,service_id,status,amount_kobo', 'wallet:id,user_id,type', 'creditTransaction.entries'])
            ->chunkById(200, function (Collection $chunk) use ($slugs, &$commissions, &$problems) {
                $purchases = $chunk->pluck('purchase')->filter();
                $referrers = $this->referrersOf($purchases);
                $attempted = FailedCommissionAttempt::query()->whereIn('purchase_id', $purchases->pluck('id'))->pluck('purchase_id')->flip()->all();
                foreach ($chunk as $commission) {
                    $commissions++;
                    array_push($problems, ...$this->commissionProblems($commission, $slugs, $referrers, $attempted));
                }
            });

        FailedCommissionAttempt::query()->with('purchase:id,reference,user_id,status')
            ->chunkById(200, function (Collection $chunk) use (&$attempts, &$problems) {
                $purchases = $chunk->pluck('purchase')->filter();
                $referrers = $this->referrersOf($purchases);
                $paid = Commission::query()->whereIn('purchase_id', $purchases->pluck('id'))->pluck('purchase_id')->flip()->all();
                foreach ($chunk as $attempt) {
                    $attempts++;
                    array_push($problems, ...$this->attemptProblems($attempt, $referrers, $paid));
                }
            });

        return [$commissions, $attempts, [...$problems, ...$this->ledgerProblems()]];
    }

    /**
     * The referrer of each purchase's buyer, by buyer id.
     *
     * @param  Collection<int, Purchase>  $purchases
     * @return array<int, int>
     */
    private function referrersOf(Collection $purchases): array
    {
        return Referral::query()->whereIn('referred_user_id', $purchases->pluck('user_id')->unique())
            ->pluck('referrer_id', 'referred_user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  array<int, string>  $slugs
     * @param  array<int, int>  $referrers
     * @param  array<int, int>  $attempted
     * @return list<string>
     */
    private function commissionProblems(Commission $commission, array $slugs, array $referrers, array $attempted): array
    {
        $purchase = $commission->purchase;
        if ($purchase === null) {
            return ["Commission {$commission->reference}: belongs to no purchase."];
        }
        $label = "Commission {$commission->reference} (purchase {$purchase->reference})";
        $problems = [];

        if ($purchase->status !== PurchaseStatus::Successful) {
            $problems[] = "{$label}: its purchase is {$purchase->status->value}, not successful.";
        }
        if (! QualifyingServices::includes($slugs[(int) $purchase->service_id] ?? null)) {
            $problems[] = "{$label}: its purchase is not of a qualifying service.";
        }
        if (($referrers[$purchase->user_id] ?? null) !== $commission->referrer_id || $commission->referrer_id === (int) $purchase->user_id) {
            $problems[] = "{$label}: it is not credited to the buyer's referrer.";
        }
        if (isset($attempted[$purchase->id])) {
            $problems[] = "{$label}: its purchase also has a failed commission attempt.";
        }
        if ($commission->base_amount_kobo !== $purchase->amount_kobo) {
            $problems[] = "{$label}: its base amount is not the purchase amount.";
        }
        if ($commission->rate_bps < 1 || $commission->rate_bps > BasisPoints::MAX || $commission->cap_kobo < 1 || $commission->amount_kobo < 1
            || $commission->amount_kobo !== Commission::amountFor($commission->base_amount_kobo, $commission->rate_bps, $commission->cap_kobo)) {
            $problems[] = "{$label}: its amount is not its rate of the base amount, capped and rounded down, and at least 1 kobo.";
        }
        $wallet = $commission->wallet;
        if ($wallet === null || (int) $wallet->user_id !== $commission->referrer_id || $wallet->type !== WalletType::Main) {
            $problems[] = "{$label}: it is not on the referrer's Main Wallet.";
        }
        if ($commission->credited_at === null) {
            $problems[] = "{$label}: has no time of credit.";
        }

        return [...$problems, ...$this->creditProblems($label, $commission, $purchase)];
    }

    /** @return list<string> */
    private function creditProblems(string $label, Commission $commission, Purchase $purchase): array
    {
        $credit = $commission->creditTransaction;
        if ($credit === null) {
            return ["{$label}: has no credit transaction."];
        }
        $problems = [];

        if ($credit->type !== TransactionType::Commission || $credit->direction !== Direction::Credit) {
            $problems[] = "{$label}: its credit {$credit->reference} is not a commission credit.";
        }
        if ($credit->status !== TransactionStatus::Successful) {
            $problems[] = "{$label}: its credit {$credit->reference} is {$credit->status->value}, not successful.";
        }
        if ($credit->amount_kobo !== $commission->amount_kobo) {
            $problems[] = "{$label}: its credit {$credit->reference} is ".Money::format($credit->amount_kobo)
                .' but the commission is '.Money::format($commission->amount_kobo).'.';
        }
        if ((int) $credit->wallet_id !== $commission->wallet_id || (int) $credit->user_id !== $commission->referrer_id) {
            $problems[] = "{$label}: its credit {$credit->reference} belongs to another wallet or customer.";
        }
        if ($credit->idempotency_key !== 'commission:'.$purchase->reference) {
            $problems[] = "{$label}: its credit {$credit->reference} was not posted for this purchase.";
        }
        $entries = $credit->entries;
        $entry = $entries->first();
        if ($entries->count() !== 1 || $entry->entry_type !== LedgerEntryType::CommissionCredit || $entry->direction !== Direction::Credit
            || $entry->amount_kobo !== $commission->amount_kobo || $entry->reverses_entry_id !== null) {
            $problems[] = "{$label}: its credit {$credit->reference} is not one commission credit ledger entry of the commission amount.";
        }

        return $problems;
    }

    /**
     * @param  array<int, int>  $referrers
     * @param  array<int, int>  $paid
     * @return list<string>
     */
    private function attemptProblems(FailedCommissionAttempt $attempt, array $referrers, array $paid): array
    {
        $purchase = $attempt->purchase;
        if ($purchase === null) {
            return ["Failed commission attempt #{$attempt->id}: belongs to no purchase."];
        }
        $label = "Failed commission attempt for purchase {$purchase->reference}";
        $problems = [];

        if (! in_array($attempt->getRawOriginal('reason_code'), CommissionFailureReason::values(), true)) {
            $problems[] = "{$label}: has an unknown reason code.";
        }
        if ($purchase->status !== PurchaseStatus::Successful) {
            $problems[] = "{$label}: its purchase is {$purchase->status->value}, not successful.";
        }
        if (($referrers[$purchase->user_id] ?? null) !== $attempt->referrer_id) {
            $problems[] = "{$label}: it does not name the buyer's referrer (customer #{$attempt->referrer_id}).";
        }
        if (isset($paid[$purchase->id])) {
            $problems[] = "{$label}: its purchase also has a commission.";
        }

        return $problems;
    }

    /**
     * Commission credits that belong to no commission, and the totals.
     *
     * @return list<string>
     */
    private function ledgerProblems(): array
    {
        $problems = [];
        $credits = fn () => Transaction::query()->where('type', TransactionType::Commission->value)->where('direction', Direction::Credit->value);

        $orphans = $credits()->whereNotIn('id', Commission::query()->select('credit_transaction_id'))->orderBy('id')->pluck('reference');
        foreach ($orphans as $reference) {
            $problems[] = "Transaction {$reference}: a commission credit that belongs to no commission.";
        }

        $credited = (int) $credits()->where('status', TransactionStatus::Successful->value)->sum('amount_kobo');
        $recorded = (int) Commission::query()->sum('amount_kobo');
        if ($credited !== $recorded) {
            $problems[] = 'Totals: successful commission credits add up to '.Money::format($credited).', but commissions add up to '.Money::format($recorded).'.';
        }

        return $problems;
    }
}
