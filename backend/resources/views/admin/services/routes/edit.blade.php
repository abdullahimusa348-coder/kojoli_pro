@extends('layouts.admin')

@section('title', 'Edit route · '.$plan->name.' · Admin · '.config('app.name'))
@section('heading', 'Edit provider route')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services.plans.routes', $plan) }}" class="text-sm text-brand-700 hover:underline">← Back to provider routes</a>
        <form method="POST" action="{{ route('admin.services.plans.routes.update', [$plan, $route]) }}" class="mt-4 grid gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:grid-cols-2 sm:p-6" novalidate>
            @csrf
            @method('PUT')
            <div class="sm:col-span-2">
                <h2 class="break-words text-lg font-semibold text-navy-900">{{ $route->provider->name }} · priority {{ $route->priority }}</h2>
                <p class="mt-0.5 break-words text-sm text-navy-600">{{ $plan->name }} · {{ $plan->product->name }} · {{ $plan->product->service->name }}</p>
                <p class="mt-2 text-xs text-navy-500">The provider of a route cannot change; use Move up / Move down to reorder, or disable the route and add another.</p>
            </div>
            @include('admin.services.routes.fields', [
                'codeValue' => old('provider_plan_code', $route->provider_plan_code),
                'costValue' => old('cost', $costInput),
                'discountValue' => old('cost_discount', $discountInput),
            ])
            <div class="flex justify-end sm:col-span-2"><x-button class="sm:w-auto">Save route</x-button></div>
        </form>
    </div>
@endsection
