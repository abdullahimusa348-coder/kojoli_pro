@extends('layouts.app')

@section('title', 'Verification submission · Nadabo Global Data')

@section('page')
    <a href="{{ route('kyc') }}" class="text-sm text-brand-700 hover:underline">← Verification</a>
    <section class="mt-3 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="submission-heading" data-kyc-submission-page="{{ $submission['reference'] }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h1 id="submission-heading" class="text-xl font-semibold text-navy-900">Verification submission</h1>
            <span class="text-sm font-medium text-navy-800" data-kyc-submission-status="{{ $submission['status']->value }}">{{ $submission['status']->label() }}</span>
        </div>

        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Reference</dt><dd class="mt-0.5 break-all font-mono text-xs text-navy-900">{{ $submission['reference'] }}</dd></div>
            <div><dt class="text-navy-600">Sent</dt><dd class="mt-0.5 text-navy-900">{{ $submission['submitted_at']?->format('j M Y, H:i') }}</dd></div>
        </dl>

        <h2 class="mt-6 text-sm font-semibold text-navy-900">Requested</h2>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-navy-800">
            @foreach ($submission['requirements'] as $label)
                <li>{{ $label }}</li>
            @endforeach
        </ul>

        @if ($submission['identities'] !== [])
            <h2 class="mt-6 text-sm font-semibold text-navy-900">Numbers sent (masked)</h2>
            <dl class="mt-2 space-y-2 text-sm">
                @foreach ($submission['identities'] as $identity)
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-navy-600">{{ $identity['label'] }}</dt>
                        <dd class="font-mono tabular-nums text-navy-900">{{ $identity['mask'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </section>
@endsection
