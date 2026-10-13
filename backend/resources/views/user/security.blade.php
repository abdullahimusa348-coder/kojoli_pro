@extends('layouts.app')

@section('title', 'Security · Nadabo Global Data')

@section('page')
    <h1 class="text-2xl font-semibold text-navy-900 sm:text-3xl">Security</h1>
    <p class="mt-1 text-sm text-navy-600">Manage your password and where your account is signed in.</p>

    @if (session('status') && session('status') !== 'password-updated' && session('status') !== 'verification-link-sent')
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- Change password --}}
        <section id="change-password" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="password-heading" data-security-section="password">
            <h2 id="password-heading" class="text-lg font-semibold text-navy-900">Change password</h2>
            <p class="mt-1 text-sm text-navy-600">Changing your password signs out your other browsers and apps.</p>
            @if (session('status') === 'password-updated')
                <x-alert class="mt-4">Password updated. Other devices have been signed out.</x-alert>
            @endif

            <form method="POST" action="{{ route('profile.password.update') }}" class="mt-4" novalidate>
                @csrf
                @method('PUT')
                <x-input name="current_password" label="Current password" type="password" bag="updatePassword" autocomplete="current-password" required />
                <x-input name="password" label="New password" type="password" bag="updatePassword" autocomplete="new-password" required />
                <x-input name="password_confirmation" label="Confirm new password" type="password" bag="updatePassword" autocomplete="new-password" required />
                <x-button class="sm:w-auto">Update password</x-button>
            </form>
        </section>

        <div class="space-y-6">
            {{-- Email verification: only when the setting is on --}}
            @if ($verificationRequired)
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="verification-heading" data-security-section="email-verification">
                    <h2 id="verification-heading" class="text-lg font-semibold text-navy-900">Email verification</h2>
                    <p class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <span @class([
                            'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-green-50 text-green-800' => $emailStatus['tone'] === 'good',
                            'bg-amber-50 text-amber-800' => $emailStatus['tone'] === 'warn',
                            'bg-navy-100 text-navy-700' => $emailStatus['tone'] === 'neutral',
                        ]) data-verification-status>{{ $emailStatus['label'] }}</span>
                        <span class="break-all text-navy-600">{{ $user->email }}</span>
                    </p>
                    @if (session('status') === 'verification-link-sent')
                        <x-alert class="mt-4">A new verification link has been sent to your email address.</x-alert>
                    @endif
                    @unless ($user->hasVerifiedEmail())
                        <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
                            @csrf
                            <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Resend verification email</button>
                        </form>
                    @endunless
                </section>
            @endif

            {{-- Signed-in browsers --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="sessions-heading" data-security-section="sessions">
                <h2 id="sessions-heading" class="text-lg font-semibold text-navy-900">Signed-in browsers</h2>

                @if (! $sessionsAvailable)
                    <p class="mt-2 text-sm text-navy-600">The list of signed-in browsers is not available on this server.</p>
                @else
                    <ul class="mt-3 divide-y divide-navy-100" role="list">
                        @foreach ($sessions as $session)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3" data-session @if ($session['current']) data-session-current @endif>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-navy-900">
                                        {{ $session['device'] }}
                                        @if ($session['current'])
                                            <span class="ml-1 rounded-full bg-green-50 px-2 py-0.5 text-xs font-semibold text-green-800">This browser</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-navy-600">
                                        <span data-session-ip>{{ $session['ip'] }}</span> ·
                                        {{ $session['current'] ? 'Active now' : 'Last active '.$session['last_active']->diffForHumans() }}
                                    </p>
                                </div>
                                @unless ($session['current'])
                                    <form method="POST" action="{{ route('security.sessions.destroy', $session['ref']) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 ring-1 ring-red-200 hover:bg-red-50">Log out</button>
                                    </form>
                                @endunless
                            </li>
                        @endforeach
                    </ul>

                    @if (count($sessions) > 1)
                        <form method="POST" action="{{ route('security.sessions.logout-others') }}" class="mt-4 border-t border-navy-100 pt-4" novalidate>
                            @csrf
                            <p class="mb-3 text-sm text-navy-700">Log out every other browser. Enter your current password to confirm.</p>
                            @php($confirmError = $errors->getBag('logoutOthers')->first('current_password'))
                            <div class="mb-4">
                                <label for="logout-others-password" class="mb-1 block text-sm font-medium text-navy-800">Current password</label>
                                <input id="logout-others-password" name="current_password" type="password" autocomplete="current-password" required
                                       @class(['block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200', 'border-red-400' => $confirmError, 'border-navy-200' => ! $confirmError])
                                       @if ($confirmError) aria-invalid="true" aria-describedby="logout-others-password-error" @endif>
                                @if ($confirmError)
                                    <p id="logout-others-password-error" class="mt-1 text-sm text-red-600">{{ $confirmError }}</p>
                                @endif
                            </div>
                            <button type="submit" class="w-full rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800 sm:w-auto">Log out all other browsers</button>
                        </form>
                    @endif
                @endif
            </section>

            {{-- Signed-in apps (API tokens) --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" aria-labelledby="apps-heading" data-security-section="apps">
                <h2 id="apps-heading" class="text-lg font-semibold text-navy-900">Signed-in apps</h2>
                <p class="mt-1 text-sm text-navy-600">Apps signed in to your account, such as the Nadabo Global Data mobile app.</p>

                @if ($tokens->isEmpty())
                    <p class="mt-3 text-sm text-navy-600" data-no-tokens>No apps are signed in.</p>
                @else
                    <ul class="mt-3 divide-y divide-navy-100" role="list">
                        @foreach ($tokens as $token)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3" data-token>
                                <div class="min-w-0">
                                    <p class="break-words text-sm font-medium text-navy-900">{{ $token->name }}</p>
                                    <p class="text-xs text-navy-600">
                                        Signed in {{ $token->created_at?->format('j M Y') }} ·
                                        {{ $token->last_used_at ? 'Last used '.$token->last_used_at->diffForHumans() : 'Not used yet' }}
                                        @if ($token->expires_at)
                                            · Expires {{ $token->expires_at->format('j M Y') }}
                                        @endif
                                    </p>
                                </div>
                                <form method="POST" action="{{ route('security.tokens.destroy', $token->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 ring-1 ring-red-200 hover:bg-red-50">Revoke</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>

                    <form method="POST" action="{{ route('security.tokens.destroy-all') }}" class="mt-4 border-t border-navy-100 pt-4" novalidate>
                        @csrf
                        @method('DELETE')
                        <p class="mb-3 text-sm text-navy-700">Sign out every app. Enter your current password to confirm.</p>
                        @php($confirmError = $errors->getBag('revokeTokens')->first('current_password'))
                            <div class="mb-4">
                                <label for="revoke-tokens-password" class="mb-1 block text-sm font-medium text-navy-800">Current password</label>
                                <input id="revoke-tokens-password" name="current_password" type="password" autocomplete="current-password" required
                                       @class(['block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200', 'border-red-400' => $confirmError, 'border-navy-200' => ! $confirmError])
                                       @if ($confirmError) aria-invalid="true" aria-describedby="revoke-tokens-password-error" @endif>
                                @if ($confirmError)
                                    <p id="revoke-tokens-password-error" class="mt-1 text-sm text-red-600">{{ $confirmError }}</p>
                                @endif
                            </div>
                        <button type="submit" class="w-full rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800 sm:w-auto">Revoke all apps</button>
                    </form>
                @endif
            </section>
        </div>
    </div>
@endsection
