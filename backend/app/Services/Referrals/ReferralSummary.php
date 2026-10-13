<?php

namespace App\Services\Referrals;

use App\Models\Commission;
use App\Models\Referral;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\Enums\UserStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\QualifyingServices;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A referrer's figures, anonymous referral history and commission history for their Referral
 * page (Phase 12). Read-only and always derived from the records as they
 * are now: nothing is stored or written here. The referral history reads only each
 * referred customer's join date and account status, never a name, email,
 * phone or id. The commission history reads only the credit date, the original
 * amount and the status as it is now: never a reference, a buyer, a staff member,
 * a reason or a wallet transaction. Both hand the page plain values.
 */
class ReferralSummary
{
    public const PER_PAGE = 20;

    /** @return array{referred: int, successful: int, earned_kobo: int, this_month_kobo: int} */
    public function figures(User $referrer): array
    {
        [$monthStart, $monthEnd] = BusinessTime::thisMonth();
        // Credited commissions only: a reversed or cancelled one is left out (none exist before the commission engine).
        $credited = fn () => Commission::where('referrer_id', $referrer->id)->whereDoesntHave('action');

        return [
            'referred' => Referral::where('referrer_id', $referrer->id)->count(),
            // A successful referral: a referred customer with at least one successful purchase of a qualifying service,
            // whatever their customer type now and whether or not it earned a commission.
            'successful' => Referral::where('referrer_id', $referrer->id)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('purchases')
                    ->join('services', 'services.id', '=', 'purchases.service_id')
                    ->whereColumn('purchases.user_id', 'referrals.referred_user_id')
                    ->where('purchases.status', PurchaseStatus::Successful->value)
                    ->whereIn('services.slug', QualifyingServices::SLUGS))
                ->count(),
            'earned_kobo' => (int) $credited()->sum('amount_kobo'),
            'this_month_kobo' => (int) $credited()->where('credited_at', '>=', $monthStart)->where('credited_at', '<', $monthEnd)->sum('amount_kobo'),
        ];
    }

    /**
     * The customers $referrer referred, newest first: joined date (Business timezone) and Active/Disabled only.
     *
     * @return LengthAwarePaginator<int, array{joined: string, status: string}>
     */
    public function history(User $referrer): LengthAwarePaginator
    {
        $zone = BusinessTime::timezone();

        return Referral::query()
            ->join('users', 'users.id', '=', 'referrals.referred_user_id')
            ->where('referrals.referrer_id', $referrer->id)
            ->orderByDesc('referrals.id')
            ->paginate(self::PER_PAGE, ['users.created_at as joined_at', 'users.status as account_status'], 'history')
            ->through(fn (Referral $row) => [
                'joined' => CarbonImmutable::parse($row->getAttribute('joined_at'), config('app.timezone'))->setTimezone($zone)->format('j M Y'),
                'status' => UserStatus::from($row->getAttribute('account_status'))->label(),
            ]);
    }

    /**
     * The commissions $referrer earned, newest credit first: the credit date (Business timezone), the original amount
     * and the status as it is now. A reversed or cancelled commission stays listed, at its original amount.
     *
     * @return LengthAwarePaginator<int, array{date: string, amount_kobo: int, status: string, status_key: string}>
     */
    public function commissions(User $referrer): LengthAwarePaginator
    {
        $zone = BusinessTime::timezone();

        return Commission::query()
            ->with('action:id,commission_id,type')
            ->where('referrer_id', $referrer->id)
            ->orderByDesc('credited_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'amount_kobo', 'credited_at'], 'commissions')
            ->withQueryString()
            ->through(function (Commission $commission) use ($zone) {
                $status = $commission->status();

                return [
                    'date' => CarbonImmutable::instance($commission->credited_at)->setTimezone($zone)->format('j M Y'),
                    'amount_kobo' => $commission->amount_kobo,
                    'status' => $status->label(),
                    'status_key' => $status->value,
                ];
            });
    }
}
