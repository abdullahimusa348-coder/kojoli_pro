{{-- Transaction status badge. $status: TransactionStatus --}}
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $status === \App\Support\Wallet\TransactionStatus::Successful,
    'bg-amber-50 text-amber-800' => $status === \App\Support\Wallet\TransactionStatus::Pending,
    'bg-red-50 text-red-800' => $status === \App\Support\Wallet\TransactionStatus::Failed,
    'bg-navy-100 text-navy-700' => $status === \App\Support\Wallet\TransactionStatus::Reversed,
]) data-transaction-status="{{ $status->value }}">{{ $status->label() }}</span>
