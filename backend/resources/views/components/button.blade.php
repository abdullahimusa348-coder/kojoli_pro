<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex w-full items-center justify-center rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-brand-600 disabled:opacity-60']) }}>
    {{ $slot }}
</button>
