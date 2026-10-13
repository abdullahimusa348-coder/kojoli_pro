<span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white" aria-hidden="true">
    {{ \Illuminate\Support\Str::of($customer->name)->explode(' ')->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') }}
</span>
