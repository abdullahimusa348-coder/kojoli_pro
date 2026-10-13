@extends('layouts.guest')

@section('title', 'Log in · '.config('app.name'))
@section('heading', 'Log in to your account')

@section('card')
    <form method="POST" action="{{ route('login') }}" novalidate>
        @csrf
        <x-input name="login" label="Email or phone number" autocomplete="username" required autofocus />
        <x-input name="password" label="Password" type="password" autocomplete="current-password" required />

        <div class="mb-6 flex items-center justify-between text-sm">
            <label class="inline-flex items-center gap-2 text-navy-700">
                <input type="checkbox" name="remember" class="rounded border-navy-300 text-brand-600"> Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-brand-700 hover:underline">Forgot password?</a>
        </div>

        <x-button>Log in</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-navy-700">
        New here? <a href="{{ route('register') }}" class="font-medium text-brand-700 hover:underline">Create an account</a>
    </p>
@endsection
