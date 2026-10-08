@extends('layouts.admin')

@section('title', $commission->reference.' · Commissions · Admin · '.config('app.name'))
@section('heading', 'Commission details')

@php($card = 'mt-6 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6')
@php($control = 'block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($money = fn (int $kobo) => \App\Support\Money::format($kobo))
@php($action = $commission->action)
{{-- A transaction reference, linked for staff who can view transactions (escaped either way). --}}
@php($transactionLink = fn (\App\Models\Transaction $tx) => $canViewTransactions ? '<a href="'.e(route('admin.transactions.show', $tx)).'" class="text-brand-700 hover:underline">'.e($tx->reference).'</a>' : e($tx->reference))

@section('page')
    <a href="{{ route('admin.referrals') }}" class="text-sm text-brand-700 hover:underline">← Back to Commissions</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <section class="mt-4 max-w-4xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="commission-heading" data-commission-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 id="commission-heading" class="break-all font-mono text-base font-semibold text-navy-900">{{ $commission->reference }}</h2>
                <p class="mt-0.5 text-xs text-navy-500">Credited to the referrer's Main Wallet when the purchase succeeded. Never changed.</p>
            </div>
            @include('admin.referrals.partials.status', ['status' => $commission->status()])
        </div>
        <dl class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Commission</dt><dd class="mt-0.5 font-semibold tabular-nums text-navy-900" data-commission-amount>{{ $money($commission->amount_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Credited</dt><dd class="mt-0.5 text-navy-900">{{ $commission->credited_at?->format('j M Y, H:i:s') }}</dd></div>
            <div><dt class="text-navy-600">Purchase amount</dt><dd class="mt-0.5 tabular-nums text-navy-900">{{ $money($commission->base_amount_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Rate and cap used</dt><dd class="mt-0.5 text-navy-900">{{ \App\Support\Pricing\BasisPoints::label($commission->rate_bps) }}, cap {{ $money($commission->cap_kobo) }}</dd></div>
            <div><dt class="text-navy-600">Referrer</dt><dd class="mt-0.5 min-w-0 text-navy-900" data-commission-referrer>
                @if ($canViewCustomers)
                    <a href="{{ route('admin.users.show', $commission->referrer) }}" class="break-words font-medium text-brand-700 hover:underline">{{ $commission->referrer->name }}</a>
                @else
                    <span class="break-words font-medium">{{ $commission->referrer->name }}</span>
                @endif
                <span class="block break-all text-xs text-navy-500">{{ $commission->referrer->email }} · Customer #{{ $commission->referrer->id }}</span>
            </dd></div>
            <div><dt class="text-navy-600">Purchase</dt><dd class="mt-0.5 min-w-0 text-navy-900" data-commission-purchase>
                @if ($canViewPurchases)
                    <a href="{{ route('admin.purchases.show', $commission->purchase) }}" class="break-all font-mono text-xs text-brand-700 hover:underline">{{ $commission->purchase->reference }}</a>
                @else
                    <span class="break-all font-mono text-xs">{{ $commission->purchase->reference }}</span>
                @endif
                <span class="block text-xs text-navy-500">{{ $commission->purchase->service_name }}</span>
            </dd></div>
            <div><dt class="text-navy-600">Credit transaction</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900" data-credit-transaction>{!! $transactionLink($commission->creditTransaction) !!}</dd></div>
        </dl>
    </section>

    @if ($action)
        <section class="{{ $card }}" aria-labelledby="action-heading" data-commission-action="{{ $action->type->value }}">
            <h2 id="action-heading" class="text-base font-semibold text-navy-900">{{ $action->type->label() }}</h2>
            <p class="mt-0.5 text-sm text-navy-600">The one action this commission can ever have. It cannot be undone or changed.</p>
            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div><dt class="text-navy-600">Action reference</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900">{{ $action->reference }}</dd></div>
                <div><dt class="text-navy-600">By</dt><dd class="mt-0.5 break-words text-navy-900">{{ $action->actedBy?->name ?? 'Staff member' }} · {{ $action->created_at?->format('j M Y, H:i:s') }}</dd></div>
                <div><dt class="text-navy-600">Money</dt><dd class="mt-0.5 text-navy-900" data-action-money>
                    @if ($action->reversalTransaction)
                        {{ $money($action->reversalTransaction->amount_kobo) }} debited as a separate "Commission reversal": <span class="break-all font-mono text-xs">{!! $transactionLink($action->reversalTransaction) !!}</span>
                    @else
                        None moved.
                    @endif
                </dd></div>
                <div class="sm:col-span-2"><dt class="text-navy-600">Reason (staff only)</dt><dd class="mt-0.5 whitespace-pre-line break-words text-navy-900" data-action-reason>{{ $action->reason }}</dd></div>
            </dl>
        </section>
    @elseif ($tokens)
        <div class="mt-6 grid max-w-4xl grid-cols-1 gap-6 md:grid-cols-2">
            @foreach ([
                'reversal' => ['Reverse', route('admin.referrals.commissions.reverse', $commission), 'Takes the full '.$money($commission->amount_kobo).' back from the referrer\'s Main Wallet as a separate debit, "Commission reversal". The original credit stays as it is. Refused while the wallet is frozen or holds less than this amount: there is no partial reversal.', 'I confirm: debit '.$money($commission->amount_kobo).' from the referrer\'s Main Wallet. This cannot be undone, and the commission can have no other action.', 'Reverse commission'],
                'cancellation' => ['Cancel', route('admin.referrals.commissions.cancel', $commission), 'Voids the commission without moving any money: the referrer keeps what was credited. Also possible while the wallet is frozen.', 'I confirm: cancel this commission without moving money. This cannot be undone, and the commission can have no other action.', 'Cancel commission'],
            ] as $type => [$title, $url, $explanation, $confirmation, $button])
                @php($bag = $errors->getBag($type))
                <form method="POST" action="{{ $url }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate data-{{ $type }}-form>
                    @csrf
                    <input type="hidden" name="token" value="{{ $tokens[$type] }}">
                    <h2 class="text-base font-semibold text-navy-900">{{ $title }}</h2>
                    <p class="mt-1 text-sm text-navy-600">{{ $explanation }}</p>
                    @if ($bag->has('action') || $bag->has('token'))
                        <p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-action-error>{{ $bag->first('action') ?: $bag->first('token') }}</p>
                    @endif

                    <label for="{{ $type }}-reason" class="mb-1 mt-4 block text-sm font-medium text-navy-700">Reason</label>
                    <textarea id="{{ $type }}-reason" name="reason" rows="3" @class([$control, 'border-red-400' => $bag->has('reason'), 'border-navy-200' => ! $bag->has('reason')])
                              @if ($bag->has('reason')) aria-invalid="true" aria-describedby="{{ $type }}-reason-error" @endif>{{ $bag->any() ? old('reason') : '' }}</textarea>
                    <p class="mt-1 text-xs text-navy-500">10 to 500 characters, kept permanently and shown to staff only.</p>
                    @if ($bag->has('reason'))<p id="{{ $type }}-reason-error" class="mt-1 text-sm text-red-600">{{ $bag->first('reason') }}</p>@endif

                    <label class="mt-4 flex items-start gap-2 text-sm text-navy-800">
                        <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                        <span>{{ $confirmation }}</span>
                    </label>
                    @if ($bag->has('confirm'))<p class="mt-1 text-sm text-red-600">{{ $bag->first('confirm') }}</p>@endif

                    <div class="mt-5 flex justify-end">
                        <button type="submit" @class(['inline-flex w-full items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold text-white sm:w-auto',
                            'bg-red-700 hover:bg-red-800' => $type === 'reversal', 'bg-navy-900 hover:bg-navy-800' => $type === 'cancellation'])>{{ $button }}</button>
                    </div>
                </form>
            @endforeach
        </div>
    @else
        <p class="mt-6 max-w-4xl text-sm text-navy-600" data-no-action>No action has been taken on this commission.</p>
    @endif
@endsection
