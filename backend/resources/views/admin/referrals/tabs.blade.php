{{-- Referral & Commission area tabs: Commissions | Rates & caps. --}}
@php($referralsTab = request()->routeIs('admin.referrals.rates*') ? 'rates' : 'commissions')
<div class="border-b border-navy-100">
    <nav class="-mb-px flex gap-x-5 overflow-x-auto" aria-label="Referral & Commission sections">
        @foreach (['commissions' => ['Commissions', route('admin.referrals')], 'rates' => ['Rates & caps', route('admin.referrals.rates')]] as $key => [$label, $url])
            <a href="{{ $url }}" data-referrals-tab="{{ $key }}"
               @class(['shrink-0 border-b-2 px-1 pb-3 text-sm font-semibold', 'border-brand-600 text-brand-700' => $referralsTab === $key, 'border-transparent text-navy-600 hover:text-navy-900' => $referralsTab !== $key])
               @if ($referralsTab === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>

@if (session('status'))
    <x-alert class="mt-4">{{ session('status') }}</x-alert>
@endif
@if ($errors->any())
    <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
@endif
