@extends('layouts.guest')

@section('title', 'Forgot password · '.config('app.name'))
@section('heading', 'Reset your password')

@section('card')
    <p class="mb-4 text-sm text-navy-700">Enter your email address and we will send you a link to choose a new password.</p>

    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf
        <x-input name="email" label="Email address" type="email" autocomplete="email" required autofocus />
        <x-button>Email reset link</x-button>
    </form>

    <p class="mt-6 text-center text-sm"><a href="{{ route('login') }}" class="text-brand-700 hover:underline">Back to log in</a></p>
@endsection
