@extends('layouts.guest')

@section('title', 'Choose a new password · '.config('app.name'))
@section('heading', 'Choose a new password')

@section('card')
    <form method="POST" action="{{ route('password.store') }}" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-input name="email" label="Email address" type="email" :value="$request->query('email')" autocomplete="email" required />
        <x-input name="password" label="New password" type="password" autocomplete="new-password" required autofocus />
        <x-input name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />
        <x-button>Reset password</x-button>
    </form>
@endsection
