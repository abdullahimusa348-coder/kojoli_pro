@extends('layouts.admin')

@section('title', $module->label().' · Admin · '.config('app.name'))
@section('heading', $module->label())

@section('page')
    <div class="rounded-2xl bg-white px-6 py-14 text-center shadow-sm ring-1 ring-navy-100" data-placeholder="{{ $module->value }}">
        <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-navy-50 text-navy-500">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $module->icon() }}"/></svg>
        </span>
        <h2 class="mt-4 text-lg font-semibold text-navy-900">{{ $module->label() }} is not built yet</h2>
        <p class="mx-auto mt-2 max-w-md text-sm text-navy-600">
            This section is a placeholder. It is planned for Phase {{ $module->plannedPhase() }} of the roadmap and has no data or actions yet.
        </p>
        <a href="{{ route('admin.dashboard') }}" class="mt-6 inline-flex rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Back to dashboard</a>
    </div>
@endsection
