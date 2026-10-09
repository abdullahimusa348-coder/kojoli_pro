@extends('layouts.app')

@section('title', 'Buy '.$label.' · Nadabo Global Data')

@section('page')
    <a href="{{ route('buy') }}" class="text-sm text-brand-700 hover:underline">← Back to Buy</a>
    <section class="mt-3" aria-labelledby="buy-heading">
        <h1 id="buy-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">Buy {{ $label }}</h1>
        <p class="mt-1 text-sm text-navy-600">Wallet balance: <span class="font-semibold tabular-nums text-navy-900" data-balance>{{ \App\Support\Money::format($balanceKobo) }}</span></p>
    </section>

    @if ($errors->has('purchase') && ! $maintenance)
        <x-alert type="error" class="mt-4" data-purchase-error>{{ $errors->first('purchase') }}</x-alert>
    @endif

    <section class="mt-6 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-buy-card data-exam-pin-buy>
        @if ($maintenance)
            @include('partials.purchases.maintenance-notice')
        @elseif ($plans->isEmpty())
            <p class="text-sm text-navy-700" data-unavailable>Not available right now. Please try again later.</p>
        @else
            {{-- One plan is one PIN: there is no quantity and nothing else to enter. --}}
            <form method="POST" action="{{ route('buy.exam-pin.confirm') }}" novalidate data-buy-form>
                @csrf
                <fieldset class="mb-4">
                    <legend class="mb-2 text-sm font-medium text-navy-800">Plan</legend>
                    <div class="grid gap-2">
                        @foreach ($plans as $row)
                            <label class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm ring-1 ring-navy-200" data-plan="{{ $row['plan']->id }}">
                                <span class="flex min-w-0 items-center gap-2">
                                    <input type="radio" name="plan" value="{{ $row['plan']->id }}" class="border-navy-300 text-brand-600 focus:ring-brand-500" @checked((string) old('plan') === (string) $row['plan']->id)>
                                    <span class="min-w-0 break-words text-navy-900">{{ $row['plan']->product->name }} · {{ $row['plan']->name }}
                                        @if ($row['plan']->description)<span class="block text-xs text-navy-500">{{ $row['plan']->description }}</span>@endif
                                    </span>
                                </span>
                                <span class="shrink-0 font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($row['quote']->amountKobo) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('plan')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </fieldset>
                <p class="mb-4 text-xs text-navy-600" data-one-pin>One PIN per purchase.</p>
                <x-button>Continue</x-button>
            </form>
        @endif
    </section>
@endsection
