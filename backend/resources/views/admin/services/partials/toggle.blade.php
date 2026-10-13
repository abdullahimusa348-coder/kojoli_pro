{{-- Enable/disable form (services.update). $action: route URL, $active: current is_active, $name: item name --}}
@can(\App\Support\Enums\SystemPermission::ServicesUpdate->value)
    <form method="POST" action="{{ $action }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="is_active" value="{{ $active ? 0 : 1 }}">
        @if ($active)
            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-navy-800 ring-1 ring-navy-200 hover:bg-navy-50" data-toggle="disable">Disable</button>
        @else
            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-medium text-green-800 ring-1 ring-green-200 hover:bg-green-50" data-toggle="enable">Enable</button>
        @endif
    </form>
@endcan
