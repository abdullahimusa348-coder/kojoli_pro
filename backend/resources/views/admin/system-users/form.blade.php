{{-- Shared create/edit fields. Password fields are never pre-filled. --}}
@php($editing = isset($staff))
@php($selectClass = 'block w-full rounded-lg border px-3 py-2 text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200 disabled:bg-navy-50')

<x-input name="name" label="Full name" :value="$staff->name ?? null" autocomplete="off" required />
<x-input name="email" label="Email address" type="email" :value="$staff->email ?? null" autocomplete="off" required />
<x-input name="phone" label="Phone number (optional)" type="tel" :value="$staff->phone ?? null" placeholder="08012345678" autocomplete="off" />

<div class="mb-4">
    <label for="role" class="mb-1 block text-sm font-medium text-navy-800">Role</label>
    @if ($editing && ($isSelf ?? false))
        <input type="hidden" name="role" value="{{ $staff->primaryRoleName() }}">
        <select id="role" disabled class="{{ $selectClass }} border-navy-200"><option>{{ $staff->roleLabels() }}</option></select>
        <p class="mt-1 text-xs text-navy-500">You cannot change your own role.</p>
    @else
        <select id="role" name="role" required @class([$selectClass, 'border-red-400' => $errors->has('role'), 'border-navy-200' => ! $errors->has('role')])>
            <option value="">Choose a role</option>
            @foreach ($roles as $roleName => $roleLabel)
                <option value="{{ $roleName }}" @selected(old('role', $editing ? $staff->primaryRoleName() : null) === $roleName)>{{ $roleLabel }}</option>
            @endforeach
        </select>
        @error('role')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    @endif
</div>

@unless ($editing)
    <div class="mb-4">
        <label for="status" class="mb-1 block text-sm font-medium text-navy-800">Status</label>
        <select id="status" name="status" class="{{ $selectClass }} border-navy-200">
            <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
            <option value="disabled" @selected(old('status') === 'disabled')>Inactive</option>
        </select>
        @error('status')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
    </div>
@endunless

<fieldset class="mt-6 border-t border-navy-100 pt-4">
    <legend class="text-sm font-semibold text-navy-900">{{ $editing ? 'Change password' : 'Password' }}</legend>
    <p class="mb-3 mt-1 text-xs text-navy-600">
        {{ $editing ? 'Leave blank to keep the current password. ' : '' }}At least 8 characters, with upper and lower case letters and a number.
    </p>
    <x-input name="password" label="{{ $editing ? 'New password' : 'Password' }}" type="password" autocomplete="new-password" :required="! $editing" />
    <x-input name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" :required="! $editing" />
</fieldset>
