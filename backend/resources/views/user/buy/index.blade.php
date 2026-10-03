@extends('layouts.app')

@section('title', 'Buy · Nadabo Global Data')

@section('page')
    <section aria-labelledby="buy-heading">
        <h1 id="buy-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">Buy</h1>
        <p class="mt-1 text-sm text-navy-600">Pay from your wallet balance.</p>
    </section>

    <ul class="mt-6 grid gap-4 sm:grid-cols-2" role="list">
        @foreach ($services as $item)
            <li class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" data-buy-service="{{ $item['slug'] }}">
                <h2 class="text-base font-semibold text-navy-900">{{ $item['label'] }}</h2>
                @if ($item['available'])
                    <a href="{{ route('buy.service', $item['slug']) }}" class="mt-3 inline-flex items-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Buy {{ $item['label'] }}</a>
                @else
                    <p class="mt-2 text-sm text-navy-500" data-unavailable>Not available right now.</p>
                @endif
            </li>
        @endforeach
    </ul>
@endsection
