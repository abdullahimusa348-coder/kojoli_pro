{{-- Admin sidebar. Shows only modules the signed-in staff member may access. --}}
<nav class="flex w-full flex-col overflow-y-auto bg-navy-950 text-navy-100" aria-label="Admin">
    <div class="flex h-16 shrink-0 items-center justify-between px-5">
        <a href="{{ route('admin.dashboard') }}" class="text-base font-bold tracking-tight text-white">
            {{ config('app.name') }}
            <span class="block text-xs font-medium uppercase tracking-wider text-brand-300">Admin</span>
        </a>
        @if ($mobile)
            <button type="button" class="rounded-md p-2 text-navy-200 hover:bg-navy-800 hover:text-white" @click="sidebarOpen = false" aria-label="Close menu">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        @endif
    </div>

    <div class="flex-1 space-y-6 px-3 pb-6">
        @foreach ($navGroups as $group => $modules)
            <div>
                <p class="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-navy-400">{{ $group }}</p>
                <ul class="space-y-1">
                    @foreach ($modules as $module)
                        @php($active = request()->routeIs($module->routeName()))
                        <li>
                            <a href="{{ route($module->routeName()) }}"
                               @class([
                                   'group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                   'bg-brand-600 text-white' => $active,
                                   'text-navy-200 hover:bg-navy-800 hover:text-white' => ! $active,
                               ])
                               @if ($active) aria-current="page" @endif
                               data-nav="{{ $module->value }}">
                                <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $module->icon() }}"/>
                                </svg>
                                <span class="flex-1 truncate">{{ $module->label() }}</span>
                                @if ($module->plannedPhase() !== null)
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-navy-500" title="Coming soon (Phase {{ $module->plannedPhase() }})" aria-hidden="true"></span>
                                    <span class="sr-only">(coming soon)</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</nav>
