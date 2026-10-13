<header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-navy-100 bg-white px-4 sm:px-6 lg:px-8">
    <button type="button" class="-ml-2 rounded-md p-2 text-navy-700 hover:bg-navy-50 lg:hidden" @click="sidebarOpen = true" aria-label="Open menu">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
    </button>

    <h1 class="min-w-0 flex-1 truncate text-lg font-semibold text-navy-900">@yield('heading', 'Dashboard')</h1>

    {{-- Profile menu --}}
    <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
        <button type="button" class="flex items-center gap-2 rounded-full p-1 text-left hover:bg-navy-50 sm:rounded-lg sm:pr-2"
                @click="open = !open" :aria-expanded="open" aria-haspopup="true" aria-label="Account menu">
            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-navy-900 text-sm font-semibold text-white" aria-hidden="true">
                {{ \Illuminate\Support\Str::of($staff->name)->explode(' ')->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') }}
            </span>
            <span class="hidden text-sm sm:block">
                <span class="block font-medium text-navy-900">{{ $staff->name }}</span>
                <span class="block text-xs text-navy-600">{{ $staff->roleLabels() }}</span>
            </span>
            <svg class="hidden h-4 w-4 text-navy-500 sm:block" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/></svg>
        </button>

        <div class="absolute right-0 mt-2 w-64 origin-top-right rounded-xl bg-white py-2 shadow-lg ring-1 ring-navy-100"
             x-show="open" x-cloak x-transition.origin.top.right role="menu">
            <div class="border-b border-navy-100 px-4 pb-3 pt-1">
                <p class="truncate text-sm font-medium text-navy-900">{{ $staff->name }}</p>
                <p class="truncate text-xs text-navy-600">{{ $staff->email }}</p>
                <p class="mt-1 text-xs font-medium text-brand-700">{{ $staff->roleLabels() }}</p>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}" class="px-2 pt-2">
                @csrf
                <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-2 py-2 text-sm text-navy-800 hover:bg-navy-50" role="menuitem">
                    <svg class="h-5 w-5 text-navy-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>
                    Log out
                </button>
            </form>
        </div>
    </div>
</header>
