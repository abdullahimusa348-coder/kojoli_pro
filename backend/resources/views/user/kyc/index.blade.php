@extends('layouts.app')

@section('title', 'Verification · Nadabo Global Data')

@section('page')
    <section aria-labelledby="verification-heading">
        <h1 id="verification-heading" class="text-2xl font-semibold text-navy-900 sm:text-3xl">Verification</h1>
    </section>

    <section class="mt-6 max-w-xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="status-heading" data-kyc-status="{{ $status->value }}">
        <h2 id="status-heading" class="text-base font-semibold text-navy-900">Your status</h2>
        <p class="mt-2 text-sm font-medium text-navy-900">{{ $status->label() }}</p>

        @if ($requirements->isEmpty())
            <p class="mt-4 text-sm text-navy-700" data-kyc-required="none">No verification is required for your account.</p>
        @else
            <h2 class="mt-5 text-base font-semibold text-navy-900">Required for your account</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-navy-800" data-kyc-required="list">
                @foreach ($requirements as $requirement)
                    <li data-kyc-requirement="{{ $requirement->key }}">{{ $requirement->label }}</li>
                @endforeach
            </ul>
            <p class="mt-4 text-sm text-navy-600">Sending verification details is not available yet.</p>
        @endif
    </section>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="submissions-heading">
        <h2 id="submissions-heading" class="px-5 pb-2 pt-5 text-base font-semibold text-navy-900">Your submissions</h2>
        @if ($submissions->isEmpty())
            <p class="px-5 pb-6 text-sm text-navy-500">No verification details have been sent yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($submissions as $submission)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" data-kyc-submission="{{ $submission['reference'] }}">
                        <div class="min-w-0">
                            <a href="{{ route('kyc.submissions.show', $submission['reference']) }}" class="break-all font-mono text-xs font-medium text-navy-900 hover:text-brand-700">{{ $submission['reference'] }}</a>
                            <p class="text-xs text-navy-500">{{ $submission['submitted_at']?->format('j M Y, H:i') }}</p>
                        </div>
                        <span class="text-sm font-medium text-navy-800" data-kyc-submission-status="{{ $submission['status']->value }}">{{ $submission['status']->label() }}</span>
                    </li>
                @endforeach
            </ul>
            @include('admin.services.partials.pagination', ['paginator' => $submissions])
        @endif
    </section>
@endsection
