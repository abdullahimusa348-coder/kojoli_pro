@extends('layouts.base')

{{-- Customer area layout (mobile-first). Navigation comes only from App\Support\Customer\CustomerNav. --}}
@php($customer = auth('web')->user())
@php($primaryNav = \App\Support\Customer\CustomerNav::primaryFor($customer))
@php($menuNav = \App\Support\Customer\CustomerNav::menuFor($customer))

@section('content')
    <div class="min-h-screen bg-navy-50" x-data="{ menuOpen: false }" @keydown.escape.window="menuOpen = false">
        <header class="sticky top-0 z-30 bg-navy-900 text-white shadow-sm">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
                @include('layouts.partials.customer.brand')

                {{-- Desktop: primary links + account menu --}}
                <nav class="hidden items-center gap-1 md:flex" aria-label="Main">
                    @foreach ($primaryNav as $item)
                        <a href="{{ $item->url() }}" data-customer-nav="{{ $item->value }}"
                           @class([
                               'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                               'bg-navy-800 text-white' => $item->isActive(),
                               'text-navy-100 hover:bg-navy-800 hover:text-white' => ! $item->isActive(),
                           ])
                           @if ($item->isActive()) aria-current="page" @endif>{{ $item->label() }}</a>
                    @endforeach

                    <div class="relative ml-2" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                        <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-navy-800"
                                @click="open = !open" :aria-expanded="open" aria-haspopup="true" aria-label="Account menu">
                            @include('layouts.partials.customer.avatar')
                            <span class="max-w-40 truncate font-medium">{{ $customer->name }}</span>
                            <svg class="h-4 w-4 text-navy-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
                        </button>
                        <div class="absolute right-0 mt-2 w-72 origin-top-right rounded-xl bg-white py-2 text-navy-900 shadow-lg ring-1 ring-navy-100"
                             x-show="open" x-cloak x-transition.origin.top.right role="menu">
                            @include('layouts.partials.customer.menu-items', ['context' => 'desktop'])
                        </div>
                    </div>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 pb-28 pt-6 md:pb-12 md:pt-8">
            @yield('page')
        </main>

        {{-- Mobile: bottom navigation --}}
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-navy-100 bg-white pb-[env(safe-area-inset-bottom)] md:hidden" aria-label="Main">
            {{-- Column count follows the nav list (+1 for Menu); set inline because Tailwind cannot see built class names. --}}
            <div class="mx-auto grid max-w-md" style="grid-template-columns: repeat({{ count($primaryNav) + 1 }}, minmax(0, 1fr))">
                @foreach ($primaryNav as $item)
                    <a href="{{ $item->url() }}" data-customer-bottom-nav="{{ $item->value }}"
                       @class([
                           'flex flex-col items-center gap-1 px-2 py-2.5 text-xs font-medium',
                           'text-brand-700' => $item->isActive(),
                           'text-navy-500 hover:text-navy-800' => ! $item->isActive(),
                       ])
                       @if ($item->isActive()) aria-current="page" @endif>
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item->icon() }}"/></svg>
                        {{ $item->label() }}
                    </a>
                @endforeach
                <button type="button" data-customer-bottom-nav="menu" @click="menuOpen = true" :aria-expanded="menuOpen" aria-controls="customer-menu"
                        class="flex flex-col items-center gap-1 px-2 py-2.5 text-xs font-medium text-navy-500 hover:text-navy-800">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                    Menu
                </button>
            </div>
        </nav>

        {{-- Mobile: menu panel (secondary account/security pages) --}}
        <div id="customer-menu" class="relative z-40 md:hidden" x-show="menuOpen" x-cloak role="dialog" aria-modal="true" aria-label="Menu">
            <div class="fixed inset-0 bg-navy-950/50" @click="menuOpen = false" x-show="menuOpen" x-transition.opacity></div>
            <div class="fixed inset-x-0 bottom-0 rounded-t-2xl bg-white pb-[env(safe-area-inset-bottom)] text-navy-900 shadow-xl" x-show="menuOpen"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
                <div class="flex items-center justify-between px-4 pt-3">
                    <span class="mx-auto h-1.5 w-10 rounded-full bg-navy-200" aria-hidden="true"></span>
                </div>
                <div class="flex items-center justify-between px-4 pb-1 pt-2">
                    <p class="text-sm font-semibold text-navy-900">Menu</p>
                    <button type="button" class="rounded-md p-2 text-navy-500 hover:bg-navy-50" @click="menuOpen = false" aria-label="Close menu">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="pb-3">
                    @include('layouts.partials.customer.menu-items', ['context' => 'mobile'])
                </div>
            </div>
        </div>
    </div>
@endsection
