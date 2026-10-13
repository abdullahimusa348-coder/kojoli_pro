@extends('layouts.admin')

@section('title', 'KYC review · Admin · '.config('app.name'))
@section('heading', 'KYC review')

@section('page')
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    <p class="max-w-3xl text-sm text-navy-600">
        Submissions waiting for a decision, oldest first. Identity values are shown masked, on each submission's own page.
        A decision is recorded once and cannot be changed.
    </p>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" data-kyc-queue>
        @if ($submissions->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500" data-kyc-queue-empty>No submissions are waiting for a decision.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($submissions as $submission)
                    <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:justify-between" data-kyc-submission="{{ $submission->reference }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.kyc.submissions.show', $submission) }}"
                               class="break-all font-mono text-sm font-semibold text-brand-700 hover:underline">{{ $submission->reference }}</a>
                            <p class="mt-0.5 break-words text-sm text-navy-800" data-kyc-customer>
                                @if ($canViewCustomers)
                                    {{ $submission->user->name }}
                                    <span class="break-all text-navy-500">· {{ $submission->user->email }}</span>
                                @else
                                    Customer #{{ $submission->user_id }}
                                @endif
                            </p>
                            <p class="mt-0.5 break-words text-xs text-navy-500">
                                {{ $submission->kind->label() }} · {{ $submission->user_type->label() }} · sent {{ $submission->submitted_at?->format('j M Y, H:i') }}
                            </p>
                        </div>
                        <div class="min-w-0 text-sm text-navy-700 sm:max-w-xs sm:text-right">
                            <p class="break-words" data-kyc-requirements>{{ $submission->requirements->pluck('label')->implode(', ') }}</p>
                            @if (($phoneMatches[$submission->id] ?? null) === 'matches')
                                <p class="mt-0.5 text-xs text-navy-600" data-kyc-phone="matches">Phone matches</p>
                            @elseif (($phoneMatches[$submission->id] ?? null) === 'changed')
                                <p class="mt-0.5 text-xs font-medium text-red-700" data-kyc-phone="changed">Phone has changed</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @include('admin.services.partials.pagination', ['paginator' => $submissions])
@endsection
