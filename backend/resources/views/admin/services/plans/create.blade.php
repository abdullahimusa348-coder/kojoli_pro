@extends('layouts.admin')

@section('title', 'Add plan · Admin · '.config('app.name'))
@section('heading', 'Add plan')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.services.plans') }}" class="text-sm text-brand-700 hover:underline">← Back to Plans</a>
        <form method="POST" action="{{ route('admin.services.plans.store') }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @include('admin.services.plans.fields', ['plan' => null])
            <div class="flex justify-end"><x-button class="sm:w-auto">Create plan</x-button></div>
        </form>
    </div>
@endsection
