@extends('layouts.admin')

@section('title', 'Purchases · Admin · '.config('app.name'))
@section('heading', 'Purchases')

@section('page')
    <p class="max-w-3xl text-sm text-navy-600">Customer purchases. A purchase is settled only by a definite provider outcome; unclear outcomes stay pending and are re-checked automatically, then move to review after 24 hours.</p>

    @php($tile = 'block rounded-xl p-3 shadow-sm ring-1')
    <section class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" aria-label="Purchase monitoring" data-purchase-monitor>
        <a href="{{ route('admin.purchases', ['status' => 'pending']) }}" class="{{ $tile }} bg-white ring-navy-100 hover:ring-navy-300" data-monitor="pending">
            <span class="block text-xs font-medium text-navy-600">Pending</span>
            <span class="mt-1 block text-xl font-semibold tabular-nums text-navy-900">{{ $monitor['pending'] }}</span>
        </a>
        <a href="{{ route('admin.purchases', ['status' => 'review']) }}" @class([$tile, 'bg-purple-50 ring-purple-200' => $monitor['review'] > 0, 'bg-white ring-navy-100 hover:ring-navy-300' => $monitor['review'] === 0]) data-monitor="review" data-review-count>
            <span class="block text-xs font-medium text-navy-600">In review</span>
            <span @class(['mt-1 block text-xl font-semibold tabular-nums', 'text-purple-800' => $monitor['review'] > 0, 'text-navy-900' => $monitor['review'] === 0])>{{ $monitor['review'] }}</span>
        </a>
        <a href="{{ route('admin.purchases', ['overdue' => 1]) }}" @class([$tile, 'bg-amber-50 ring-amber-200' => $monitor['overdue'] > 0, 'bg-white ring-navy-100 hover:ring-navy-300' => $monitor['overdue'] === 0]) data-monitor="overdue">
            <span class="block text-xs font-medium text-navy-600">Overdue checks</span>
            <span @class(['mt-1 block text-xl font-semibold tabular-nums', 'text-amber-800' => $monitor['overdue'] > 0, 'text-navy-900' => $monitor['overdue'] === 0])>{{ $monitor['overdue'] }}</span>
        </a>
        <div class="{{ $tile }} bg-white ring-navy-100" data-monitor="oldest-pending">
            <span class="block text-xs font-medium text-navy-600">Oldest pending</span>
            <span class="mt-1 block break-words text-xl font-semibold text-navy-900">{{ $monitor['oldest_pending_at']?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) ?? '—' }}</span>
        </div>
        <a href="{{ route('admin.purchases', ['status' => 'successful', 'completed' => 'today']) }}" class="{{ $tile }} bg-white ring-navy-100 hover:ring-navy-300" data-monitor="successful-today">
            <span class="block text-xs font-medium text-navy-600">Successful today</span>
            <span class="mt-1 block text-xl font-semibold tabular-nums text-navy-900">{{ $monitor['successful_today'] }}</span>
        </a>
        <a href="{{ route('admin.purchases', ['status' => 'failed', 'completed' => 'today']) }}" class="{{ $tile }} bg-white ring-navy-100 hover:ring-navy-300" data-monitor="failed-today">
            <span class="block text-xs font-medium text-navy-600">Failed today</span>
            <span class="mt-1 block text-xl font-semibold tabular-nums text-navy-900">{{ $monitor['failed_today'] }}</span>
        </a>
    </section>
    <p class="mt-2 text-xs text-navy-500">“Today” is the business day in {{ $businessTimezone }}. A check is overdue when it has been due for more than {{ config('purchases.overdue_after_minutes') }} minutes; re-checks run every five minutes, so this usually means the scheduler is not running.</p>

    @php($control = 'block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200')
    <form method="GET" action="{{ route('admin.purchases') }}" class="mt-6 grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-navy-100 sm:grid-cols-2 lg:grid-cols-6" role="search" data-purchase-filters>
        <div class="sm:col-span-2 lg:col-span-6 xl:col-span-2">
            <label for="q" class="mb-1 block text-xs font-medium text-navy-700">Search</label>
            <input id="q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, phone number, customer" class="{{ $control }}">
        </div>
        @foreach ([
            'status' => ['Status', collect($statuses)->map(fn ($s) => [$s->value, $s->label()]), 'All statuses'],
            'service' => ['Service', $services->map(fn ($s) => [(string) $s->id, $s->name]), 'All services'],
            'network' => ['Network', collect($networks)->map(fn ($n) => [$n->value, $n->label()]), 'All networks'],
            'provider' => ['Provider', $providers->map(fn ($p) => [(string) $p->id, $p->name]), 'All providers'],
        ] as $name => [$label, $options, $all])
            <div class="lg:col-span-3 xl:col-span-1">
                <label for="{{ $name }}" class="mb-1 block text-xs font-medium text-navy-700">{{ $label }}</label>
                <select id="{{ $name }}" name="{{ $name }}" class="{{ $control }}">
                    <option value="">{{ $all }}</option>
                    @foreach ($options as [$value, $text])
                        <option value="{{ $value }}" @selected((string) ($filters[$name] ?? '') === (string) $value)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="from" class="mb-1 block text-xs font-medium text-navy-700">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="{{ $control }}">
        </div>
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="to" class="mb-1 block text-xs font-medium text-navy-700">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="{{ $control }}">
        </div>
        @if (($filters['overdue'] ?? null) === '1')<input type="hidden" name="overdue" value="1">@endif
        <div class="lg:col-span-3 xl:col-span-1">
            <label for="completed" class="mb-1 block text-xs font-medium text-navy-700">Completed</label>
            <select id="completed" name="completed" class="{{ $control }}">
                <option value="">Any time</option>
                <option value="today" @selected(($filters['completed'] ?? '') === 'today')>Today ({{ $businessTimezone }})</option>
            </select>
        </div>
        @if ($errors->any())<p class="text-sm text-red-600 sm:col-span-2 lg:col-span-6">{{ $errors->first() }}</p>@endif
        <div class="flex gap-2 sm:col-span-2 sm:justify-end lg:col-span-6">
            <a href="{{ route('admin.purchases') }}" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-navy-700 hover:bg-navy-50">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Apply</button>
        </div>
    </form>

    @if (($filters['overdue'] ?? null) === '1')
        <p class="mt-4 text-sm text-navy-700" data-overdue-filter>Showing pending and review purchases whose status check is overdue. <a href="{{ route('admin.purchases') }}" class="text-brand-700 hover:underline">Show all</a></p>
    @endif
    @if (($filters['completed'] ?? null) === 'today')
        <p class="mt-4 text-sm text-navy-700" data-completed-today>Showing purchases completed today: midnight to midnight in {{ $businessTimezone }}. Times in the list are in UTC.</p>
    @endif

    <div class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-navy-100">
        @if ($purchases->isEmpty())
            <div class="px-5 py-12 text-center">
                <p class="text-sm font-medium text-navy-800">No purchases found</p>
                <p class="mt-1 text-sm text-navy-500">{{ array_filter($filters) ? 'Try a different search or filter.' : 'Customer purchases appear here once customers start buying.' }}</p>
            </div>
        @else
            <ul class="divide-y divide-navy-100" role="list">
                @foreach ($purchases as $purchase)
                    <li class="flex flex-col gap-2 px-5 py-4 md:flex-row md:items-center" data-purchase="{{ $purchase->reference }}">
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.purchases.show', $purchase) }}" class="block break-all font-mono text-xs font-semibold text-brand-700 hover:underline">{{ $purchase->reference }}</a>
                            <p class="break-words text-sm text-navy-800">{{ $purchase->service_name }} · {{ $purchase->product_name }} · {{ $purchase->plan_name }} → <span class="tabular-nums">{{ $purchase->recipient }}</span></p>
                            <p class="break-all text-xs text-navy-500">{{ $purchase->user->name }} ({{ $purchase->user->email }}) · {{ $purchase->created_at?->format('j M Y, H:i') }}@if ($purchase->successfulAttempt) · via {{ $purchase->successfulAttempt->provider->name }} (route {{ $purchase->successfulAttempt->route_priority }})@endif</p>
                            @unless ($purchase->isFinal())
                                <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-navy-600" data-next-check>
                                    @if ($purchase->review_since)<span data-review-since>In review since {{ $purchase->review_since->format('j M Y, H:i') }} ·</span>@endif
                                    <span>Next check {{ $purchase->checkDueAt()?->format('j M Y, H:i') }}</span>
                                    @if (in_array($purchase->id, $overdueIds, true))<span class="rounded bg-amber-50 px-1.5 py-0.5 font-semibold text-amber-800 ring-1 ring-amber-200" data-overdue>Overdue</span>@endif
                                </p>
                            @endunless
                        </div>
                        <div class="flex items-center gap-3">
                            @include('partials.purchases.status-badge', ['status' => $purchase->status])
                            <span class="font-semibold tabular-nums text-navy-900">{{ \App\Support\Money::format($purchase->amount_kobo) }}</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @include('admin.services.partials.pagination', ['paginator' => $purchases])
@endsection
