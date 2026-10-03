@extends('layouts.base')

@section('content')
    <div class="min-h-screen bg-navy-50">
        <header class="bg-navy-900 text-white" x-data="{ open: false }">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
                <a href="{{ route('dashboard') }}" class="text-lg font-bold tracking-tight">{{ config('app.name') }}</a>

                <button type="button" class="rounded p-2 sm:hidden" @click="open = !open" :aria-expanded="open" aria-label="Menu">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>

                <nav class="hidden items-center gap-6 text-sm sm:flex">
                    @include('layouts.partials.nav-links')
                </nav>
            </div>
            <nav class="space-y-1 border-t border-navy-700 px-4 py-3 text-sm sm:hidden" x-show="open" x-cloak>
                @include('layouts.partials.nav-links')
            </nav>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            @yield('page')
        </main>
    </div>
@endsection
