@extends('layouts.base')

@section('content')
    <main class="flex min-h-screen flex-col items-center justify-center bg-navy-50 px-4 py-10">
        <a href="{{ url('/') }}" class="mb-6 text-2xl font-bold tracking-tight text-navy-900">
            {{ config('app.name') }}
        </a>

        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-sm ring-1 ring-navy-100 sm:p-8">
            @hasSection('heading')
                <h1 class="mb-6 text-xl font-semibold text-navy-900">@yield('heading')</h1>
            @endif

            @if (session('status'))
                <x-alert>{{ session('status') }}</x-alert>
            @endif

            @yield('card')
        </div>
    </main>
@endsection
