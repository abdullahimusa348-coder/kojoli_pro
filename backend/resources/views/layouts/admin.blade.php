@extends('layouts.base')

@section('content')
    <div class="min-h-screen bg-navy-50">
        <header class="bg-navy-950 text-white">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-3">
                <a href="{{ route('admin.dashboard') }}" class="text-lg font-bold tracking-tight">
                    {{ config('app.name') }} <span class="font-normal text-brand-200">Admin</span>
                </a>
                <form method="POST" action="{{ route('admin.logout') }}" class="text-sm">
                    @csrf
                    <button type="submit" class="py-1 hover:text-brand-200">Log out</button>
                </form>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            @yield('page')
        </main>
    </div>
@endsection
