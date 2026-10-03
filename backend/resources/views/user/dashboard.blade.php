@extends('layouts.app')

@section('title', 'Dashboard · Nadabo Global Data')

@section('page')
    {{-- Welcome --}}
    <section aria-labelledby="welcome-heading">
        <p class="text-sm font-medium text-brand-700">Welcome back</p>
        <h1 id="welcome-heading" class="mt-1 text-2xl font-semibold text-navy-900 sm:text-3xl">{{ $user->name }}</h1>
        <p class="mt-1 text-sm text-navy-600">Here is a summary of your Nadabo Global Data account.</p>
    </section>

    {{-- Email verification prompt: only when verification is on and this email is unverified --}}
    @if ($showVerificationPrompt)
        <section class="mt-6 flex flex-col gap-3 rounded-2xl bg-amber-50 p-5 ring-1 ring-amber-200 sm:flex-row sm:items-center sm:justify-between" data-verification-prompt>
            <div>
                <p class="text-sm font-semibold text-amber-900">Please verify your email address</p>
                <p class="mt-1 text-sm text-amber-800">We sent a verification link to {{ $user->email }}. Verify it to keep full access to your account.</p>
            </div>
            <a href="{{ route('verification.notice') }}" class="inline-flex shrink-0 items-center justify-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Verify email</a>
        </section>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        {{-- Account summary --}}
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6 lg:col-span-2" aria-labelledby="summary-heading" data-account-summary>
            <h2 id="summary-heading" class="text-base font-semibold text-navy-900">Account summary</h2>
            <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-navy-600">Name</dt>
                    <dd class="mt-0.5 break-words font-medium text-navy-900" data-summary="name">{{ $user->name }}</dd>
                </div>
                <div>
                    <dt class="text-navy-600">Account type</dt>
                    <dd class="mt-0.5 font-medium text-navy-900" data-summary="type">{{ $user->user_type->label() }}</dd>
                </div>
                <div>
                    <dt class="text-navy-600">Account status</dt>
                    <dd class="mt-0.5" data-summary="status">
                        <span @class([
                            'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-green-50 text-green-800' => $user->isActive(),
                            'bg-red-50 text-red-800' => ! $user->isActive(),
                        ])>{{ $user->status->label() }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-navy-600">Member since</dt>
                    <dd class="mt-0.5 font-medium text-navy-900" data-summary="member-since">
                        <time datetime="{{ $user->created_at?->toDateString() }}">{{ $user->created_at?->format('j F Y') }}</time>
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-navy-600">Email verification</dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-2" data-summary="email-verification">
                        <span @class([
                            'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            'bg-green-50 text-green-800' => $emailStatus['tone'] === 'good',
                            'bg-amber-50 text-amber-800' => $emailStatus['tone'] === 'warn',
                            'bg-navy-100 text-navy-700' => $emailStatus['tone'] === 'neutral',
                        ])>{{ $emailStatus['label'] }}</span>
                        <span class="break-all text-navy-600">{{ $user->email }}</span>
                    </dd>
                </div>
            </dl>
        </section>

        {{-- Shortcuts to existing account pages --}}
        <section aria-labelledby="shortcuts-heading">
            <h2 id="shortcuts-heading" class="sr-only">Shortcuts</h2>
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1" role="list">
                @foreach ($shortcuts as $shortcut)
                    <li>
                        <a href="{{ $shortcut['item']->url() }}" data-shortcut="{{ $shortcut['item']->value }}"
                           class="flex h-full items-start gap-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 transition hover:ring-brand-300 focus-visible:outline-brand-600">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $shortcut['item']->icon() }}"/></svg>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-navy-900">{{ $shortcut['item']->label() }}</span>
                                <span class="mt-1 block text-sm text-navy-600">{{ $shortcut['description'] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
@endsection
