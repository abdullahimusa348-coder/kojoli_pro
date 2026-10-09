{{-- Payment status badge. $status: PaymentStatus --}}
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $status === \App\Support\Payments\PaymentStatus::Successful,
    'bg-amber-50 text-amber-800' => $status === \App\Support\Payments\PaymentStatus::Pending,
    'bg-red-50 text-red-800' => $status === \App\Support\Payments\PaymentStatus::Failed,
    'bg-purple-50 text-purple-800' => $status === \App\Support\Payments\PaymentStatus::Review,
]) data-payment-status="{{ $status->value }}">{{ $status->label() }}</span>
