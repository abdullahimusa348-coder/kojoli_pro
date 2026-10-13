{{-- Commission status badge, derived from its single action. $status: CommissionStatus --}}
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $status === \App\Support\Referrals\CommissionStatus::Credited,
    'bg-red-50 text-red-800' => $status === \App\Support\Referrals\CommissionStatus::Reversed,
    'bg-navy-100 text-navy-700' => $status === \App\Support\Referrals\CommissionStatus::Cancelled,
]) data-commission-status="{{ $status->value }}">{{ $status->label() }}</span>
