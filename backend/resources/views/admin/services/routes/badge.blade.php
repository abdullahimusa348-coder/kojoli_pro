{{-- "Routes: n eligible of m" badge. $candidates: list<RouteCandidate>. --}}
@php($total = count($candidates))
@php($eligible = count(array_filter($candidates, fn ($c) => $c->eligible)))
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $eligible > 0,
    'bg-amber-50 text-amber-800' => $eligible === 0 && $total > 0,
    'bg-navy-50 text-navy-700' => $total === 0,
]) data-routes="{{ $eligible }}/{{ $total }}">{{ $total === 0 ? 'No routes' : "Routes {$eligible}/{$total} eligible" }}</span>
