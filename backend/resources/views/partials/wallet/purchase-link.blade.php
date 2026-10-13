{{-- "View purchase" on a purchase debit or refund: the reference comes from the transaction's metadata and must be a purchase
     reference; other transactions get nothing. The result page shows only the customer's own purchases. $transaction: ?Transaction --}}
@php($purchaseReference = $transaction?->type === \App\Support\Wallet\TransactionType::Purchase ? ($transaction->metadata['purchase'] ?? null) : null)
@if (is_string($purchaseReference) && preg_match('/^PUR-[0-9A-Z]{26}\z/', $purchaseReference) === 1)
    <a href="{{ route('purchases.show', $purchaseReference) }}" class="text-xs font-medium text-brand-700 hover:underline" data-purchase-link="{{ $purchaseReference }}">View purchase</a>
@endif
