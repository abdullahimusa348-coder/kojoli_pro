@extends('layouts.admin')

@section('title', 'Settings · Admin · '.config('app.name'))
@section('heading', 'Settings')

@section('page')
    <div class="max-w-3xl">
        <p class="text-sm text-navy-700">
            Platform configuration stored in the database. Infrastructure secrets stay in the server <code class="rounded bg-navy-100 px-1 text-xs">.env</code> file and are not shown here.
        </p>

        @if (session('status'))
            <x-alert class="mt-4">{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <x-alert type="error" class="mt-4">
                @php($fieldCount = count($errors->keys()))
                Settings were not saved. Please correct the {{ $fieldCount === 1 ? 'highlighted field' : $fieldCount.' highlighted fields' }} below.
            </x-alert>
        @endif

        @unless ($canUpdate)
            <x-alert type="error" class="mt-4">You can view settings but not change them.</x-alert>
        @endunless

        @if ($groups->isEmpty())
            <div class="mt-6 rounded-2xl bg-white px-5 py-12 text-center shadow-sm ring-1 ring-navy-100">
                <p class="text-sm text-navy-700">No settings yet. Run <code class="rounded bg-navy-100 px-1 text-xs">php artisan db:seed</code> to add the defaults.</p>
            </div>
        @else
            <form method="POST" action="{{ route('admin.settings.update') }}" class="mt-6 space-y-6" novalidate>
                @csrf
                @method('PUT')

                <fieldset @disabled(! $canUpdate) class="space-y-6">
                    @foreach ($groups as $group => $data)
                        <section class="rounded-2xl bg-white shadow-sm ring-1 ring-navy-100" data-settings-group="{{ $group }}" aria-labelledby="group-{{ $group }}">
                            <div class="border-b border-navy-100 px-5 py-4">
                                <h2 id="group-{{ $group }}" class="text-base font-semibold text-navy-900">{{ $data['label'] }}</h2>
                            </div>

                            <div class="divide-y divide-navy-100">
                                @foreach ($data['settings'] as $item)
                                    @include('admin.settings.field', $item)
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </fieldset>

                @if ($canUpdate)
                    <div class="flex justify-end">
                        <x-button class="sm:w-auto">Save settings</x-button>
                    </div>
                @endif
            </form>
        @endif
    </div>
@endsection
