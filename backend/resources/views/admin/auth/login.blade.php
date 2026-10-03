@extends('layouts.guest')

@section('title', 'Admin log in · '.config('app.name'))
@section('heading', 'Admin log in')

@section('card')
    <form method="POST" action="{{ route('admin.login.store') }}" novalidate>
        @csrf
        <x-input name="login" label="Email or phone number" autocomplete="username" required autofocus />
        <x-input name="password" label="Password" type="password" autocomplete="current-password" required />
        <x-button>Log in</x-button>
    </form>
@endsection
