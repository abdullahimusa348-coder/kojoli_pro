@extends('layouts.base')

@php($staff = auth('admin')->user())
@php($navGroups = \App\Support\Admin\AdminModule::visibleTo($staff))

@section('content')
    <div class="min-h-screen bg-navy-50" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        {{-- Mobile sidebar (off-canvas) --}}
        <div class="relative z-40 lg:hidden" x-show="sidebarOpen" x-cloak role="dialog" aria-modal="true" aria-label="Admin navigation">
            <div class="fixed inset-0 bg-navy-950/60" @click="sidebarOpen = false" x-show="sidebarOpen" x-transition.opacity></div>
            <div class="fixed inset-y-0 left-0 flex w-72 max-w-[85vw]" x-show="sidebarOpen"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full">
                @include('admin.partials.sidebar', ['navGroups' => $navGroups, 'mobile' => true])
            </div>
        </div>

        {{-- Desktop sidebar --}}
        <div class="hidden lg:fixed lg:inset-y-0 lg:z-30 lg:flex lg:w-72">
            @include('admin.partials.sidebar', ['navGroups' => $navGroups, 'mobile' => false])
        </div>

        <div class="lg:pl-72">
            @include('admin.partials.topbar', ['staff' => $staff])

            <main class="px-4 py-6 sm:px-6 lg:px-8">
                @yield('page')
            </main>
        </div>
    </div>
@endsection
