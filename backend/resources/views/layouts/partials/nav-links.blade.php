<a href="{{ route('dashboard') }}" class="block py-1 hover:text-brand-200 @if (request()->routeIs('dashboard')) font-semibold @endif">Dashboard</a>
<a href="{{ route('profile.edit') }}" class="block py-1 hover:text-brand-200 @if (request()->routeIs('profile.*')) font-semibold @endif">Profile</a>
<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit" class="py-1 hover:text-brand-200">Log out</button>
</form>
