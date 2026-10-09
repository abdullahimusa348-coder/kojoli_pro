@extends('layouts.guest')

@section('title', 'Create account · '.config('app.name'))
@section('heading', 'Create your account')

@section('card')
    <form method="POST" action="{{ route('register') }}" novalidate>
        @csrf
        <x-input name="name" label="Full name" autocomplete="name" required autofocus />
        <x-input name="email" label="Email address" type="email" autocomplete="email" required />
        <x-input name="phone" label="Phone number" type="tel" autocomplete="tel" placeholder="08012345678" required />
        <x-input name="password" label="Password" type="password" autocomplete="new-password" required />
        <x-input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required />
        <p class="mb-6 text-xs text-navy-600">At least 8 characters, with upper and lower case letters and a number.</p>
        <x-input name="referral_code" label="Referral code (optional)" :value="$referralCode ?? null" autocomplete="off" autocapitalize="characters" spellcheck="false" />

        <x-button>Create account</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-navy-700">
        Already registered? <a href="{{ route('login') }}" class="font-medium text-brand-700 hover:underline">Log in</a>
    </p>
@endsection
