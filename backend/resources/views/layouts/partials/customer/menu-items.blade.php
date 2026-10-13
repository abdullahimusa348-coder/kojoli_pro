{{-- Account menu: who is signed in, secondary pages from CustomerNav, and log out. --}}
<div class="border-b border-navy-100 px-4 pb-3 pt-1">
    <p class="truncate text-sm font-semibold text-navy-900">{{ $customer->name }}</p>
    <p class="truncate text-xs text-navy-600">{{ $customer->email }}</p>
    <p class="mt-1 text-xs font-medium text-brand-700">{{ $customer->user_type->label() }}</p>
</div>
<ul class="px-2 pt-2" role="none">
    @foreach ($menuNav as $item)
        <li role="none">
            <a href="{{ $item->url() }}" role="menuitem" data-customer-menu="{{ $item->value }}" @click="menuOpen = false"
               class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-navy-800 hover:bg-navy-50">
                <svg class="h-5 w-5 text-navy-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item->icon() }}"/></svg>
                {{ $item->label() }}
            </a>
        </li>
    @endforeach
    <li role="none">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" role="menuitem" data-customer-menu="logout" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-navy-800 hover:bg-navy-50">
                <svg class="h-5 w-5 text-navy-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>
                Log out
            </button>
        </form>
    </li>
</ul>
