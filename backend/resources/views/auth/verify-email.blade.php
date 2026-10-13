@extends('layouts.guest')

@section('title', 'Verify your email · '.config('app.name'))
@section('heading', 'Verify your email address')

@section('card')
    <p class="mb-4 text-sm text-navy-700">
        We sent a verification link to <span class="font-medium">{{ auth()->user()->email }}</span>.
        Click the link in that email to continue. If it has not arrived, we can send another.
    </p>

    @if (session('status') === 'verification-link-sent')
        <x-alert>A new verification link has been sent to your email address.</x-alert>
    @endif

    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-button>Resend verification email</x-button>
    </form>

    <div class="mt-6 flex items-center justify-between text-sm">
        <a href="{{ route('profile.edit') }}" class="text-brand-700 hover:underline">Change email address</a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-navy-700 hover:underline">Log out</button>
        </form>
    </div>
@endsection
