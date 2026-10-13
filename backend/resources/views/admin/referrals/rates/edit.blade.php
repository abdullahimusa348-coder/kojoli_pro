@extends('layouts.admin')

@section('title', 'Edit rate · '.$service->name.' · Admin · '.config('app.name'))
@section('heading', 'Referral & Commission')

@php($control = 'block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
{{-- After a stale-form refusal, show the values saved now rather than the refused ones. --}}
@php($stale = $errors->has('setting'))
@php($currentRate = \App\Support\Pricing\BasisPoints::toInput($setting?->rate_bps))
@php($currentCap = \App\Support\Pricing\KoboAmount::toInput($setting?->cap_kobo))
@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.referrals.rates') }}" class="text-sm text-brand-700 hover:underline">← Back to rates &amp; caps</a>

        <form method="POST" action="{{ route('admin.referrals.rates.update', $service) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate data-commission-form="{{ $service->slug }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">

            <h2 class="break-words text-lg font-semibold text-navy-900">{{ $service->name }} commission</h2>
            <p class="mt-0.5 break-words text-sm text-navy-600" data-current>
                Now: {{ $setting ? 'rate '.\App\Support\Pricing\BasisPoints::label($setting->rate_bps).', cap '.\App\Support\Money::format($setting->cap_kobo).' per purchase' : 'not set (no commission)' }}
            </p>
            <p class="mt-3 text-sm text-navy-700">
                The referrer earns this percentage of the purchase amount, up to the cap for each purchase (rounded down to the kobo). Use 0 for no commission.
                Rate 0 to 99.99%; cap up to {{ \App\Support\Money::format($maxAmountKobo) }} (system setting).
            </p>

            @error('setting')<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-stale>{{ $message }}</p>@enderror
            @error('fingerprint')<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>@enderror

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach (['rate' => ['Rate (%)', $currentRate, 'e.g. 2.5'], 'cap' => ['Cap per purchase (₦)', $currentCap, 'e.g. 100.00']] as $field => [$label, $current, $hint])
                    <div>
                        <label for="{{ $field }}" class="mb-1 block text-sm font-medium text-navy-700">{{ $label }}</label>
                        <input id="{{ $field }}" name="{{ $field }}" inputmode="decimal" autocomplete="off" placeholder="{{ $hint }}"
                               value="{{ $stale ? $current : old($field, $current) }}" @class([$control, 'border-red-400' => $errors->has($field), 'border-navy-200' => ! $errors->has($field)])
                               @if ($errors->has($field)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif>
                        @error($field)<p id="{{ $field }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                <label for="reason" class="mb-1 block text-sm font-medium text-navy-700">Reason for this change</label>
                <textarea id="reason" name="reason" rows="3" @class([$control, 'border-red-400' => $errors->has('reason'), 'border-navy-200' => ! $errors->has('reason')])
                          @if ($errors->has('reason')) aria-invalid="true" aria-describedby="reason-error" @endif>{{ old('reason') }}</textarea>
                <p class="mt-1 text-xs text-navy-500">10 to 500 characters, kept permanently in the rate history.</p>
                @error('reason')<p id="reason-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <label class="mt-5 flex items-start gap-2 text-sm text-navy-800">
                <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                <span>I have checked these values. They apply to purchases that succeed after saving and are recorded in the rate history; commissions already credited are not changed.</span>
            </label>
            @error('confirm')<p class="mt-1 text-sm text-red-600" id="confirm-error">{{ $message }}</p>@enderror

            <div class="mt-5 flex justify-end"><x-button class="sm:w-auto">Save rate and cap</x-button></div>
        </form>
    </div>
@endsection
