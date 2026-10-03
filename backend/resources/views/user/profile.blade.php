@extends('layouts.app')

@section('title', 'Profile · '.config('app.name'))

@section('page')
    <h1 class="text-2xl font-semibold text-navy-900">Profile</h1>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-navy-100">
            <h2 class="mb-4 text-lg font-semibold text-navy-900">Account details</h2>
            @if (session('status') === 'profile-updated')
                <x-alert>Profile updated.</x-alert>
            @endif

            <dl class="mb-4 grid grid-cols-2 gap-2 text-sm">
                <dt class="text-navy-600">Account type</dt><dd class="font-medium text-navy-900">{{ $user->user_type->label() }}</dd>
                <dt class="text-navy-600">Status</dt><dd class="font-medium text-navy-900">{{ $user->status->label() }}</dd>
            </dl>

            <form method="POST" action="{{ route('profile.update') }}" novalidate>
                @csrf
                @method('PATCH')
                <x-input name="name" label="Full name" :value="$user->name" autocomplete="name" required />
                <x-input name="email" label="Email address" type="email" :value="$user->email" autocomplete="email" required />
                <x-input name="phone" label="Phone number" type="tel" :value="$user->phone" autocomplete="tel" required />
                <x-button class="sm:w-auto">Save</x-button>
            </form>
        </section>

        <section class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-navy-100">
            <h2 class="mb-4 text-lg font-semibold text-navy-900">Change password</h2>
            @if (session('status') === 'password-updated')
                <x-alert>Password updated. Other devices have been signed out.</x-alert>
            @endif

            <form method="POST" action="{{ route('profile.password.update') }}" novalidate>
                @csrf
                @method('PUT')
                <x-input name="current_password" label="Current password" type="password" bag="updatePassword" autocomplete="current-password" required />
                <x-input name="password" label="New password" type="password" bag="updatePassword" autocomplete="new-password" required />
                <x-input name="password_confirmation" label="Confirm new password" type="password" bag="updatePassword" autocomplete="new-password" required />
                <x-button class="sm:w-auto">Update password</x-button>
            </form>
        </section>
    </div>
@endsection
