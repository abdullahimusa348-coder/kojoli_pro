{{-- "Priced n/4" badge. $plan (active_prices_count loaded or counted). --}}
@php($priced = $plan->pricedCount())
@php($total = \App\Models\Plan::customerTypeCount())
<span @class([
    'inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold',
    'bg-green-50 text-green-800' => $priced === $total,
    'bg-amber-50 text-amber-800' => $priced > 0 && $priced < $total,
    'bg-navy-50 text-navy-700' => $priced === 0,
]) data-priced="{{ $priced }}/{{ $total }}">Priced {{ $priced }}/{{ $total }}</span>
