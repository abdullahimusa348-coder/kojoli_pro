{{-- Permission matrix grouped by module. Disabled when the role is read-only. --}}
<div x-data="{ filter: '' }">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-base font-semibold text-navy-900">Permissions</h2>
            <p class="text-xs text-navy-600">Modules marked “Not built yet” can be prepared now; their permissions take effect when the module is built.</p>
        </div>
        <div class="sm:w-64">
            <label for="module-filter" class="sr-only">Filter modules</label>
            <input id="module-filter" type="search" x-model="filter" placeholder="Filter modules"
                   class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
        </div>
    </div>

    @error('permissions')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
    @error('permissions.*')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

    @php($checked = old('permissions', $granted))

    <div class="mt-4 grid gap-4 md:grid-cols-2">
        @foreach ($modules as $group)
            @php($module = $group['module'])
            <fieldset class="rounded-xl p-4 ring-1 ring-navy-100" data-module="{{ $module->value }}"
                      x-show="filter === '' || @js(mb_strtolower($module->label())).includes(filter.toLowerCase())">
                <legend class="sr-only">{{ $module->label() }}</legend>
                <div class="mb-3 flex items-center justify-between gap-2">
                    <p class="text-sm font-semibold text-navy-900">{{ $module->label() }}</p>
                    @unless ($module->isBuilt())
                        <span class="shrink-0 rounded bg-navy-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-navy-600">Not built yet</span>
                    @endunless
                </div>
                <div class="space-y-2">
                    @foreach ($group['permissions'] as $permission)
                        <label class="flex items-start gap-2 text-sm text-navy-800">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->value }}"
                                   @checked(in_array($permission->value, $checked, true))
                                   class="mt-0.5 h-4 w-4 shrink-0 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                            <span>
                                {{ $permission->label() }}
                                <span class="block font-mono text-[11px] text-navy-400">{{ $permission->value }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
    </div>
</div>
