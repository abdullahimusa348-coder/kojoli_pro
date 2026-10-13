@extends('layouts.app')

@section('title', 'Buy '.$type->label().' · Nadabo Global Data')

@section('page')
    <a href="{{ route('buy') }}" class="text-sm text-brand-700 hover:underline">← Back to Buy</a>
    <section class="mt-3" aria-labelledby="buy-heading">
        <h1 id="buy-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">Buy {{ $type->label() }}</h1>
        <p class="mt-1 text-sm text-navy-600">Wallet balance: <span class="font-semibold tabular-nums text-navy-900" data-balance>{{ \App\Support\Money::format($balanceKobo) }}</span></p>
    </section>

    @if ($errors->has('purchase') && ! $maintenance)
        <x-alert type="error" class="mt-4" data-purchase-error>{{ $errors->first('purchase') }}</x-alert>
    @endif

    <section class="mt-6 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-buy-card data-identity-buy="{{ $service }}">
        @if ($maintenance)
            @include('partials.purchases.maintenance-notice')
        @elseif ($plans->isEmpty())
            <p class="text-sm text-navy-700" data-unavailable>Not available right now. Please try again later.</p>
        @else
            <form method="POST" action="{{ route("buy.{$service}.confirm") }}" novalidate data-buy-form>
                @csrf
                <fieldset class="mb-4">
                    <legend class="mb-2 text-sm font-medium text-navy-800">Plan</legend>
                    <div class="grid gap-2">
                        @foreach ($plans as $row)
                            <label class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm ring-1 ring-navy-200" data-plan="{{ $row['plan']->id }}">
                                <span class="flex min-w-0 items-center gap-2">
                                    <input type="radio" name="plan" value="{{ $row['plan']->id }}" class="border-navy-300 text-brand-600 focus:ring-brand-500" @checked((string) old('plan') === (string) $row['plan']->id)>
                                    <span class="min-w-0 break-words text-navy-900">{{ $row['plan']->name }}
                                        @if ($row['plan']->description)<span class="block text-xs text-navy-500">{{ $row['plan']->description }}</span>@endif
                                    </span>
                                </span>
                                <span class="shrink-0 font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($row['quote']->amountKobo) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('plan')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </fieldset>

                {{-- The number is never refilled from old input and never flashed: after an error it is typed again. --}}
                <div class="mb-4">
                    <label for="identity_number" class="mb-1 block text-sm font-medium text-navy-800">{{ $type->label() }}</label>
                    <input id="identity_number" name="identity_number" type="text" value="" inputmode="numeric" autocomplete="off" autocapitalize="off"
                        spellcheck="false" maxlength="30" required data-identity-input
                        @class(['block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200',
                            'border-red-400' => $errors->has('identity_number'), 'border-navy-200' => ! $errors->has('identity_number')])
                        @error('identity_number') aria-invalid="true" aria-describedby="identity_number-error" @enderror>
                    @error('identity_number')<p id="identity_number-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    <p class="mt-1 text-xs text-navy-600">The 11-digit {{ $type->label() }}.</p>
                </div>
                <x-button>Continue</x-button>
            </form>
        @endif
    </section>
@endsection
