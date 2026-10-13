@extends('layouts.admin')

@section('title', $customer->name.' · Users · Admin · '.config('app.name'))
@section('heading', 'Customer details')

@section('page')
    <a href="{{ route('admin.users') }}" class="text-sm text-brand-700 hover:underline">← Back to Users</a>

    @if (session('status'))
        <x-alert class="mt-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->any())
        <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
    @endif

    <div class="mt-4 grid gap-6 lg:grid-cols-3">
        {{-- Profile (safe fields only: no password, hash, tokens or IP address) --}}
        <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6 lg:col-span-2" aria-labelledby="profile-heading">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="profile-heading" class="truncate text-lg font-semibold text-navy-900">{{ $customer->name }}</h2>
                    <p class="text-sm text-navy-600">Customer #{{ $customer->id }}</p>
                </div>
                @if ($can['update'])
                    <a href="{{ route('admin.users.edit', $customer) }}" class="rounded-lg px-3 py-1.5 text-sm font-medium text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Edit details</a>
                @endif
            </div>

            <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2" data-customer-details>
                <div><dt class="text-navy-600">Email</dt><dd class="break-all font-medium text-navy-900">{{ $customer->email }}</dd></div>
                <div><dt class="text-navy-600">Email verified</dt><dd class="font-medium text-navy-900">{{ $customer->email_verified_at ? $customer->email_verified_at->format('j M Y') : 'Not verified' }}</dd></div>
                <div><dt class="text-navy-600">Phone</dt><dd class="font-medium text-navy-900">{{ $customer->phone ?? '—' }}</dd></div>
                <div><dt class="text-navy-600">Type</dt><dd class="font-medium text-navy-900" data-field="type">{{ $customer->user_type->label() }}</dd></div>
                <div><dt class="text-navy-600">Status</dt>
                    <dd data-field="status">
                        @if ($customer->isActive())
                            <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-800">Active</span>
                        @else
                            <span class="rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-800">Disabled</span>
                        @endif
                    </dd>
                </div>
                <div><dt class="text-navy-600">Joined</dt><dd class="font-medium text-navy-900">{{ $customer->created_at?->format('j M Y, H:i') }}</dd></div>
                <div><dt class="text-navy-600">Last login</dt><dd class="font-medium text-navy-900">{{ $customer->last_login_at?->format('j M Y, H:i') ?? 'Never' }}</dd></div>
                <div><dt class="text-navy-600">Signed-in apps (API tokens)</dt><dd class="font-medium text-navy-900" data-field="tokens">{{ $activeTokens }}</dd></div>
            </dl>
        </section>

        {{-- Actions, each shown only with its permission (and enforced on the server) --}}
        <div class="space-y-6">
            @can(\App\Support\Enums\SystemPermission::WalletView->value)
                @php($customerWallet = $customer->mainWallet())
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" aria-labelledby="wallet-heading" data-customer-wallet>
                    <h2 id="wallet-heading" class="text-base font-semibold text-navy-900">Wallet</h2>
                    <p class="mt-2 break-all text-2xl font-semibold tabular-nums text-navy-900" data-customer-wallet-balance>{{ \App\Support\Money::format($customerWallet?->balance_kobo ?? 0) }}</p>
                    <p class="mt-1 text-xs text-navy-600">{{ $customerWallet ? $customerWallet->status->label().' main wallet' : 'No wallet activity yet' }}</p>
                    <a href="{{ route('admin.wallet.show', $customer) }}" class="mt-3 inline-flex w-full justify-center rounded-lg px-4 py-2 text-sm font-semibold text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50">Open wallet</a>
                </section>
            @endcan

            @if ($can['status'])
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" aria-labelledby="status-heading">
                    <h2 id="status-heading" class="text-base font-semibold text-navy-900">Account status</h2>
                    <form method="POST" action="{{ route('admin.users.status', $customer) }}" class="mt-3">
                        @csrf
                        @method('PATCH')
                        @if ($customer->isActive())
                            <p class="text-sm text-navy-600">Disabling signs the customer out, revokes their API tokens and blocks login. Their records are kept.</p>
                            <input type="hidden" name="status" value="disabled">
                            <button type="submit" class="mt-3 w-full rounded-lg px-4 py-2 text-sm font-semibold text-red-700 ring-1 ring-red-200 hover:bg-red-50"
                                    onclick="return confirm('Disable {{ e($customer->name) }}? They will be signed out everywhere.')">Disable account</button>
                        @else
                            <p class="text-sm text-navy-600">This account is disabled and cannot sign in.</p>
                            <input type="hidden" name="status" value="active">
                            <button type="submit" class="mt-3 w-full rounded-lg px-4 py-2 text-sm font-semibold text-green-800 ring-1 ring-green-200 hover:bg-green-50">Enable account</button>
                        @endif
                    </form>
                </section>
            @endif

            @if ($can['type'])
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" aria-labelledby="type-heading">
                    <h2 id="type-heading" class="text-base font-semibold text-navy-900">Customer type</h2>
                    <form method="POST" action="{{ route('admin.users.type', $customer) }}" class="mt-3 space-y-3">
                        @csrf
                        @method('PATCH')
                        <label for="user_type" class="sr-only">Customer type</label>
                        <select id="user_type" name="user_type" class="block w-full rounded-lg border border-navy-200 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}" @selected($customer->user_type === $type)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="w-full rounded-lg bg-navy-900 px-4 py-2 text-sm font-semibold text-white hover:bg-navy-800">Change type</button>
                    </form>
                </section>
            @endif

            @if ($can['reset'])
                <section class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100" aria-labelledby="reset-heading">
                    <h2 id="reset-heading" class="text-base font-semibold text-navy-900">Password</h2>
                    <p class="mt-1 text-sm text-navy-600">Email the customer a link to choose a new password. Their current password is never shown.</p>
                    <form method="POST" action="{{ route('admin.users.password-reset', $customer) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="w-full rounded-lg px-4 py-2 text-sm font-semibold text-brand-700 ring-1 ring-navy-200 hover:bg-navy-50" @disabled(! $customer->isActive())>
                            Send password reset link
                        </button>
                    </form>
                </section>
            @endif
        </div>
    </div>
@endsection
