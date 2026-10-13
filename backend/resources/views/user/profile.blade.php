@extends('layouts.app')

@section('title', 'Account · Nadabo Global Data')

@section('page')
    <h1 class="text-2xl font-semibold text-navy-900 sm:text-3xl">Account</h1>
    <p class="mt-1 text-sm text-navy-600">Your account details. Some details can only be changed by Nadabo Global Data.</p>

    <div class="mt-6 grid gap-6 lg:grid-cols-5">
        {{-- Account information (read-only) --}}
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6 lg:col-span-2" aria-labelledby="account-info-heading" data-account-info>
            <h2 id="account-info-heading" class="text-lg font-semibold text-navy-900">Account details</h2>
            <dl class="mt-4 space-y-4 text-sm">
                <div>
                    <dt class="text-navy-600">Name</dt>
                    <dd class="mt-0.5 break-words font-medium text-navy-900" data-account="name">{{ $user->name }}</dd>
                </div>
                <div>
                    <dt class="text-navy-600">Email</dt>
                    <dd class="mt-0.5 break-all font-medium text-navy-900" data-account="email">{{ $user->email }}</dd>
                </div>
                <div>
                    <dt class="text-navy-600">Email verification</dt>
                    <dd class="mt-0.5" data-account="email-verification">
                        <span @class([
                            'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-green-50 text-green-800' => $emailStatus['tone'] === 'good',
                            'bg-amber-50 text-amber-800' => $emailStatus['tone'] === 'warn',
                            'bg-navy-100 text-navy-700' => $emailStatus['tone'] === 'neutral',
                        ])>{{ $emailStatus['label'] }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-navy-600">Phone</dt>
                    <dd class="mt-0.5 font-medium text-navy-900" data-account="phone">{{ $user->phone }}</dd>
                </div>
                <div>
                    <dt class="text-navy-600">Account type</dt>
                    <dd class="mt-0.5 font-medium text-navy-900" data-account="type">{{ $user->user_type->label() }}</dd>
                </div>
                <div>
                    <dt class="text-navy-600">Account status</dt>
                    <dd class="mt-0.5" data-account="status">
                        <span @class([
                            'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-green-50 text-green-800' => $user->isActive(),
                            'bg-red-50 text-red-800' => ! $user->isActive(),
                        ])>{{ $user->status->label() }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-navy-600">Member since</dt>
                    <dd class="mt-0.5 font-medium text-navy-900" data-account="member-since">
                        <time datetime="{{ $user->created_at?->toDateString() }}">{{ $user->created_at?->format('j F Y') }}</time>
                    </dd>
                </div>
            </dl>
            <p class="mt-4 text-xs text-navy-500">Account type and status are managed by Nadabo Global Data.</p>
        </section>

        {{-- Editable details: name and email only --}}
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6 lg:col-span-3" aria-labelledby="edit-heading">
            <h2 id="edit-heading" class="text-lg font-semibold text-navy-900">Edit details</h2>
            @if (session('status') === 'profile-updated')
                <x-alert class="mt-4">Account details updated.</x-alert>
            @endif
            @error('phone')
                <x-alert type="error" class="mt-4">{{ $message }}</x-alert>
            @enderror

            <form method="POST" action="{{ route('profile.update') }}" class="mt-4" novalidate>
                @csrf
                @method('PATCH')
                <x-input name="name" label="Full name" :value="$user->name" autocomplete="name" required />
                <x-input name="email" label="Email address" type="email" :value="$user->email" autocomplete="email" required />
                <p class="-mt-2 mb-4 text-xs text-navy-600">If you change your email address it will need to be verified again.</p>

                {{-- Read-only: not a form field, so it is never submitted. The server also rejects any phone sent here. --}}
                <div class="mb-6">
                    <label for="phone-readonly" class="mb-1 block text-sm font-medium text-navy-800">Phone number</label>
                    <input id="phone-readonly" type="tel" value="{{ $user->phone }}" readonly aria-readonly="true" aria-describedby="phone-note" data-phone-readonly
                           class="block w-full cursor-not-allowed rounded-lg border border-navy-200 bg-navy-50 px-3 py-2 text-navy-700">
                    <p id="phone-note" class="mt-1 text-xs text-navy-600">Phone number changes will require SMS verification and are not available yet.</p>
                </div>

                <x-button class="sm:w-auto">Save details</x-button>
            </form>
        </section>
    </div>

    {{-- Password and sign-in security live on the Security page --}}
    <section class="mt-6 flex flex-col gap-3 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:flex-row sm:items-center sm:justify-between sm:p-6" aria-labelledby="security-link-heading" data-security-link>
        <div>
            <h2 id="security-link-heading" class="text-lg font-semibold text-navy-900">Password &amp; security</h2>
            <p class="mt-1 text-sm text-navy-600">Change your password and manage signed-in browsers and apps on the Security page.</p>
        </div>
        <a href="{{ route('security') }}" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Go to Security</a>
    </section>
@endsection
