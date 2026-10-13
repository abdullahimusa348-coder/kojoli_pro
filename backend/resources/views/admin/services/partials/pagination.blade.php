@if ($paginator->hasPages())
    <div class="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-navy-700" data-pagination>
        <span>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
        <div class="flex gap-2">
            @if ($paginator->previousPageUrl())
                <a href="{{ $paginator->previousPageUrl() }}" class="rounded-lg px-3 py-1.5 ring-1 ring-navy-200 hover:bg-white">Previous</a>
            @endif
            @if ($paginator->nextPageUrl())
                <a href="{{ $paginator->nextPageUrl() }}" class="rounded-lg px-3 py-1.5 ring-1 ring-navy-200 hover:bg-white">Next</a>
            @endif
        </div>
    </div>
@endif
