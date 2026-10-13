@extends('layouts.app')

@section('title', 'Payment status · Nadabo Global Data')

@php($status = $payment->status)
@section('page')
    <a href="{{ route('wallet.fund') }}" class="text-sm text-brand-700 hover:underline">← Back to Fund wallet</a>

    <section class="mt-3 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="status-heading" data-payment-result="{{ $status->value }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 id="status-heading" class="text-xl font-semibold text-navy-900">
                {{ match ($status) {
                    \App\Support\Payments\PaymentStatus::Successful => 'Payment received',
                    \App\Support\Payments\PaymentStatus::Pending => 'Waiting for confirmation',
                    \App\Support\Payments\PaymentStatus::Failed => 'Payment not completed',
                    \App\Support\Payments\PaymentStatus::Review => 'Payment under review',
                } }}
            </h1>
            @include('partials.payments.status-badge', ['status' => $status])
        </div>
        <p class="mt-2 text-sm text-navy-700" data-payment-message>
            {{ match ($status) {
                \App\Support\Payments\PaymentStatus::Successful => 'Your wallet has been credited.',
                \App\Support\Payments\PaymentStatus::Pending => 'We have not received confirmation from the payment provider yet. If you paid, your wallet is credited automatically once it is confirmed; you can refresh this page.',
                \App\Support\Payments\PaymentStatus::Failed => 'This payment was not completed and your wallet was not charged or credited. You can start a new payment.',
                \App\Support\Payments\PaymentStatus::Review => 'We are checking this payment. Your wallet is credited if the payment is confirmed. Please contact support if you need help.',
            } }}
        </p>
        @if ($checkFailed)
            <p class="mt-2 text-sm text-amber-800" data-check-unavailable>We could not reach the payment provider just now. We will keep checking automatically.</p>
        @endif

        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Amount</dt><dd class="mt-0.5 font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($payment->amount_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Reference</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900">{{ $payment->reference }}</dd></div>
            <div><dt class="text-navy-600">Started</dt><dd class="mt-0.5 text-navy-900">{{ $payment->created_at?->format('j M Y, H:i') }}</dd></div>
            <div><dt class="text-navy-600">Completed</dt><dd class="mt-0.5 text-navy-900">{{ $payment->completed_at?->format('j M Y, H:i') ?? '—' }}</dd></div>
        </dl>

        <div class="mt-5 flex flex-wrap gap-2">
            @if ($status === \App\Support\Payments\PaymentStatus::Pending)
                <a href="{{ route('wallet.fund.show', $payment->reference) }}" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Check again</a>
            @endif
            <a href="{{ route('wallet') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50">Go to wallet</a>
        </div>
    </section>
@endsection
