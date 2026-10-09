@extends('layouts.admin')

@section('title', 'Edit '.$customer->name.' · Users · Admin · '.config('app.name'))
@section('heading', 'Edit customer details')

@section('page')
    <div class="max-w-2xl">
        <a href="{{ route('admin.users.show', $customer) }}" class="text-sm text-brand-700 hover:underline">← Back to customer</a>

        <form method="POST" action="{{ route('admin.users.update', $customer) }}" class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-navy-100 sm:p-6" novalidate>
            @csrf
            @method('PUT')
            <x-input name="name" label="Full name" :value="$customer->name" autocomplete="off" required />
            <x-input name="email" label="Email address" type="email" :value="$customer->email" autocomplete="off" required />
            <x-input name="phone" label="Phone number" type="tel" :value="$customer->phone" placeholder="08012345678" autocomplete="off" required />
            <p class="mb-4 text-xs text-navy-600">Changing the email marks it unverified. Type and status are changed from the customer page.</p>
            <div class="flex justify-end">
                <x-button class="sm:w-auto">Save details</x-button>
            </div>
        </form>
    </div>
@endsection
