@extends('layouts.guest')

@section('title', 'Staff log in · '.config('app.name'))
@section('heading', 'Staff log in')

@section('card')
    <form method="POST" action="{{ route('admin.login.store') }}" novalidate>
        @csrf
        <x-input name="email" label="Staff email" type="email" autocomplete="username" required autofocus />
        <x-input name="password" label="Password" type="password" autocomplete="current-password" required />
        <x-button>Log in</x-button>
    </form>
@endsection
