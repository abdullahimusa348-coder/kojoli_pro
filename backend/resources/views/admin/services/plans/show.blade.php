@extends('layouts.admin')

@section('title', $plan->name.' · Plans · Admin · '.config('app.name'))
@section('heading', 'Plan details')

@section('page')
    <a href="{{ route('admin.services.plans') }}" class="text-sm text-brand-700 hover:underline">← Back to Plans</a>
    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <section class="mt-4 max-w-3xl rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" data-plan-details>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="min-w-0 break-words text-lg font-semibold text-navy-900">{{ $plan->name }}</h2>
            <div class="flex flex-wrap gap-2">
                @can(\App\Support\Enums\SystemPermission::ServicesUpdate->value)
                    <a href="{{ route('admin.services.plans.edit', $plan) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit</a>
                @endcan
                @include('admin.services.partials.toggle', ['action' => route('admin.services.plans.status', $plan), 'active' => $plan->is_active])
            </div>
        </div>

        <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div><dt class="text-navy-600">Status</dt><dd class="mt-0.5">@include('admin.services.partials.status', ['label' => $plan->statusLabel()])</dd></div>
            <div><dt class="text-navy-600">Available</dt><dd class="mt-0.5 font-medium text-navy-900" data-available>{{ $plan->isAvailable() ? 'Yes' : 'No' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Product</dt>
                <dd class="mt-0.5 break-words font-medium">
                    <a href="{{ route('admin.services.products.show', $plan->product) }}" class="text-brand-700 hover:underline">{{ $plan->product->name }}</a>
                    <span class="text-navy-500">· {{ $plan->product->service->name }} · {{ $plan->product->service->category->name }}</span>
                </dd>
            </div>
            <div><dt class="text-navy-600">Amount type</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->amount_type->label() }}</dd></div>
            <div><dt class="text-navy-600">Data volume</dt><dd class="mt-0.5 font-medium text-navy-900" data-volume>{{ $plan->dataVolumeLabel() ?? '—' }}</dd></div>
            <div><dt class="text-navy-600">Validity</dt><dd class="mt-0.5 font-medium text-navy-900" data-validity>{{ $plan->validityLabel() ?? '—' }}</dd></div>
            <div><dt class="text-navy-600">Display order</dt><dd class="mt-0.5 font-medium text-navy-900">{{ $plan->sort_order }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Code</dt><dd class="mt-0.5 break-all font-mono text-navy-900">{{ $plan->code }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-navy-600">Description</dt><dd class="mt-0.5 break-words text-navy-900">{{ $plan->description ?: '—' }}</dd></div>
        </dl>
        @if ($plan->is_active && ! $plan->isAvailable())
            <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">This plan is active, but its product, service or category is disabled, so it is unavailable.</p>
        @endif
    </section>
@endsection
