@extends('layouts.admin')

@section('title', 'KYC requirements · Admin · '.config('app.name'))
@section('heading', 'KYC')

@php($typeLabels = collect(\App\Support\Enums\UserType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()]))
@php($describe = fn (array $state) => ($state['is_enabled'] ? 'On' : 'Off').' · '.(collect($state['user_types'])->map(fn ($value) => $typeLabels[$value] ?? $value)->implode(', ') ?: 'no customer types'))

@section('page')
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    <p class="max-w-3xl text-sm text-navy-600">
        Every requirement starts off. Turning one on records which customer types it applies to, and a requirement needs at least one customer type before it can be turned on.
        <span class="font-medium text-navy-800">Nothing enforces KYC in this version.</span> Customers are not asked for anything, and no purchase, wallet funding or account is blocked.
    </p>

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        <ul class="divide-y divide-navy-100" role="list">
            @foreach ($requirements as $requirement)
                <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center" data-kyc-requirement="{{ $requirement->key }}">
                    <div class="min-w-0 flex-1">
                        <p class="break-words text-sm font-semibold text-navy-900">{{ $requirement->label }}</p>
                        <p class="break-words text-sm text-navy-700" data-status="{{ $requirement->is_enabled ? 'on' : 'off' }}">{{ $describe($requirement->snapshot()) }}</p>
                        @if ($requirement->description)
                            <p class="mt-0.5 break-words text-xs text-navy-500">{{ $requirement->description }}</p>
                        @endif
                        <p class="mt-0.5 break-words text-xs text-navy-500">
                            {{ $requirement->updatedBy ? 'Last changed '.$requirement->updated_at?->format('j M Y, H:i').' · '.$requirement->updatedBy->name : 'Not changed since it was set up' }}
                        </p>
                    </div>
                    @if ($canEdit)
                        <a href="{{ route('admin.kyc.requirements.edit', $requirement) }}" data-edit-requirement="{{ $requirement->key }}"
                           class="inline-flex items-center justify-center rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit<span class="sr-only">&nbsp;{{ $requirement->label }}</span></a>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" aria-labelledby="history-heading" data-kyc-history>
        <h2 id="history-heading" class="border-b border-navy-100 px-5 py-3 text-base font-semibold text-navy-900">Change history</h2>
        @if ($history->isEmpty())
            <p class="px-5 py-6 text-sm text-navy-500">No changes yet.</p>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($history as $change)
                    <li class="px-5 py-3 text-sm" data-kyc-change="{{ $change->requirement->key }}">
                        <p class="break-words text-navy-900"><span class="font-semibold">{{ $change->requirement->label }}:</span> {{ $describe($change->old_state) }} → {{ $describe($change->new_state) }}</p>
                        <p class="mt-0.5 whitespace-pre-line break-words text-navy-700" data-change-reason>{{ $change->reason }}</p>
                        <p class="mt-0.5 break-words text-xs text-navy-500">{{ $change->created_at?->format('j M Y, H:i') }} · {{ $change->changedBy?->name ?? '—' }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    @include('admin.services.partials.pagination', ['paginator' => $history])
@endsection
