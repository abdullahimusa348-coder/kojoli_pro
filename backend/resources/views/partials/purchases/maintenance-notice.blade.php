{{-- Shown on the Buy pages instead of the forms while maintenance mode is on (App\Support\MaintenanceMode). --}}
<p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200" role="status" data-maintenance>{{ \App\Support\MaintenanceMode::MESSAGE }}</p>
