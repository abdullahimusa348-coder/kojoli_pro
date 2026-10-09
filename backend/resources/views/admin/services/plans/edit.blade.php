@extends('layouts.admin')

@section('title', 'Edit '.$plan->name.' · Admin · '.config('app.name'))
@section('heading', 'Edit plan')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services.plans.show', $plan) }}" class="text-sm text-brand-700 hover:underline">← Back to plan</a>
        <form method="POST" action="{{ route('admin.services.plans.update', $plan) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @method('PUT')
            @include('admin.services.plans.fields', ['plan' => $plan])
            <p class="mb-4 text-xs text-navy-600">Use Enable / Disable on the plan page to change its status.</p>
            <div class="flex justify-end"><x-button class="sm:w-auto">Save plan</x-button></div>
        </form>
    </div>
@endsection
