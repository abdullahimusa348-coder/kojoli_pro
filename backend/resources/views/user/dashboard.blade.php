@extends('layouts.app')

@section('title', 'Dashboard · '.config('app.name'))

@section('page')
    <h1 class="text-2xl font-semibold text-navy-900">Welcome, {{ $user->name }}</h1>
    <p class="mt-1 text-sm text-navy-600">Account type: <span class="font-medium text-navy-800">{{ $user->user_type->label() }}</span></p>

    <div class="mt-8 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-navy-100">
        <p class="text-navy-700">Your dashboard is ready. Services will appear here as they are launched.</p>
    </div>
@endsection
