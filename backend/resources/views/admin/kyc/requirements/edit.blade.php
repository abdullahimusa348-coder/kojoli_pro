@extends('layouts.admin')

@section('title', 'Edit KYC requirement · Admin · '.config('app.name'))
@section('heading', 'KYC')

@php($control = 'block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
@php($typeLabels = collect($customerTypes)->mapWithKeys(fn ($type) => [$type->value => $type->label()]))
{{-- After a validation error, show what was submitted. After a stale-form refusal, show what is saved now. --}}
@php($stale = $errors->has('requirement'))
@php($fromForm = session()->hasOldInput() && ! $stale)
@php($label = $fromForm ? old('label') : $requirement->label)
@php($description = $fromForm ? old('description') : $requirement->description)
@php($enabled = $fromForm ? (bool) old('enabled') : $requirement->is_enabled)
@php($selectedTypes = $fromForm ? (array) old('user_types', []) : ($requirement->user_types ?? []))
@php($typeError = collect($errors->keys())->first(fn ($key) => $key === 'user_types' || str_starts_with($key, 'user_types.')))

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.kyc.requirements') }}" class="text-sm text-brand-700 hover:underline">← Back to KYC requirements</a>

        <form method="POST" action="{{ route('admin.kyc.requirements.update', $requirement) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate data-kyc-form="{{ $requirement->key }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">

            <h2 class="break-words text-lg font-semibold text-navy-900">{{ $requirement->label }}</h2>
            <p class="mt-0.5 break-words text-sm text-navy-600" data-current>
                Now: {{ $requirement->is_enabled ? 'on' : 'off' }} · {{ $requirement->user_types ? collect($requirement->user_types)->map(fn ($value) => $typeLabels[$value] ?? $value)->implode(', ') : 'no customer types' }}
            </p>
            <p class="mt-3 text-sm text-navy-700">
                Nothing enforces this requirement in this version. Turning it on records which customer types it applies to, and it needs at least one.
            </p>

            @error('requirement')<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-stale>{{ $message }}</p>@enderror
            @error('fingerprint')<p class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $message }}</p>@enderror

            <div class="mt-4">
                <label for="label" class="mb-1 block text-sm font-medium text-navy-700">Name</label>
                <input id="label" name="label" type="text" value="{{ $label }}"
                       @class([$control, 'border-red-400' => $errors->has('label'), 'border-navy-200' => ! $errors->has('label')])
                       @if ($errors->has('label')) aria-invalid="true" aria-describedby="label-error" @endif>
                @error('label')<p id="label-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="mt-4">
                <label for="description" class="mb-1 block text-sm font-medium text-navy-700">Description (optional)</label>
                <textarea id="description" name="description" rows="2"
                          @class([$control, 'border-red-400' => $errors->has('description'), 'border-navy-200' => ! $errors->has('description')])
                          @if ($errors->has('description')) aria-invalid="true" aria-describedby="description-error" @endif>{{ $description }}</textarea>
                @error('description')<p id="description-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <input type="hidden" name="enabled" value="0">
            <label class="mt-5 flex items-start gap-2 text-sm text-navy-800">
                <input type="checkbox" name="enabled" value="1" @checked($enabled) data-enabled
                       class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                <span>On: the requirement applies to the customer types ticked below</span>
            </label>

            <fieldset class="mt-5">
                <legend class="mb-1 block text-sm font-medium text-navy-700">Customer types</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($customerTypes as $type)
                        <label class="flex items-center gap-2 text-sm text-navy-800">
                            <input type="checkbox" name="user_types[]" value="{{ $type->value }}" @checked(in_array($type->value, $selectedTypes, true))
                                   class="rounded border-navy-300 text-brand-600 focus:ring-brand-500" data-customer-type="{{ $type->value }}">
                            {{ $type->label() }}
                        </label>
                    @endforeach
                </div>
                @if ($typeError)<p class="mt-1 text-sm text-red-600" id="user_types-error">{{ $errors->first($typeError) }}</p>@endif
            </fieldset>

            <div class="mt-4">
                <label for="reason" class="mb-1 block text-sm font-medium text-navy-700">Reason for this change</label>
                <textarea id="reason" name="reason" rows="3" @class([$control, 'border-red-400' => $errors->has('reason'), 'border-navy-200' => ! $errors->has('reason')])
                          @if ($errors->has('reason')) aria-invalid="true" aria-describedby="reason-error" @endif>{{ old('reason') }}</textarea>
                <p class="mt-1 text-xs text-navy-500">10 to 500 characters, kept permanently in the change history.</p>
                @error('reason')<p id="reason-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>

            <label class="mt-5 flex items-start gap-2 text-sm text-navy-800">
                <input type="checkbox" name="confirm" value="1" class="mt-0.5 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                <span>I have checked these settings. They are recorded in the change history, and nothing enforces them in this version.</span>
            </label>
            @error('confirm')<p class="mt-1 text-sm text-red-600" id="confirm-error">{{ $message }}</p>@enderror

            <div class="mt-5 flex justify-end"><x-button class="sm:w-auto">Save requirement</x-button></div>
        </form>
    </div>
@endsection
