{{-- Customer-facing purchase status badge. $status: PurchaseStatus --}}
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $status === \App\Support\Purchases\PurchaseStatus::Successful,
    'bg-amber-50 text-amber-800' => $status === \App\Support\Purchases\PurchaseStatus::Pending,
    'bg-red-50 text-red-800' => $status === \App\Support\Purchases\PurchaseStatus::Failed,
    'bg-purple-50 text-purple-800' => $status === \App\Support\Purchases\PurchaseStatus::Review,
]) data-purchase-status="{{ $status->value }}">{{ $status->customerLabel() }}</span>
