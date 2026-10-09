<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Roles\CreateRole;
use App\Actions\Admin\Roles\DeleteRole;
use App\Actions\Admin\Roles\UpdateRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Roles\RoleRequest;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Permissions\PermissionModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Roles & Permissions. Routes enforce roles.view / create / update / delete;
 * the actions re-check them and apply the safety rules (see RoleRules).
 */
class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', 'in:built-in,custom'],
        ]);

        $builtIn = SystemRole::values();

        $roles = Role::query()
            ->where('guard_name', 'admin')
            ->withCount('permissions')
            ->get()
            ->filter(function (Role $role) use ($filters, $builtIn) {
                $isBuiltIn = in_array($role->name, $builtIn, true);
                if (($filters['type'] ?? null) === 'built-in' && ! $isBuiltIn) {
                    return false;
                }
                if (($filters['type'] ?? null) === 'custom' && $isBuiltIn) {
                    return false;
                }
                $term = mb_strtolower($filters['q'] ?? '');

                return $term === '' || str_contains(mb_strtolower(SystemRole::labelFor($role->name).' '.$role->name), $term);
            })
            // Built-in roles first in their defined order, then custom roles alphabetically.
            ->sortBy(fn (Role $role) => [array_search($role->name, $builtIn, true) === false ? 1 : 0, array_search($role->name, $builtIn, true), mb_strtolower($role->name)])
            ->values();

        // Count staff per role directly: spatie's users() relation resolves the model
        // from the default (customer) guard when used in a query.
        $staffCounts = SystemUser::query()
            ->join('model_has_roles', function ($join) {
                $join->on('model_has_roles.model_id', '=', 'system_users.id')
                    ->where('model_has_roles.model_type', (new SystemUser)->getMorphClass());
            })
            ->selectRaw('model_has_roles.role_id, count(*) as aggregate')
            ->groupBy('model_has_roles.role_id')
            ->pluck('aggregate', 'role_id');

        return view('admin.roles.index', [
            'roles' => $roles,
            'staffCounts' => $staffCounts,
            'filters' => $filters,
            'totalPermissions' => count(SystemPermission::cases()),
            'actor' => $request->user('admin'),
        ]);
    }

    public function create(): View
    {
        return view('admin.roles.create', $this->formData(null, []));
    }

    public function store(RoleRequest $request, CreateRole $create): RedirectResponse
    {
        $role = $create->handle($request->roleName(), $request->permissions(), $request->user('admin'));

        return redirect()->route('admin.roles')->with('status', 'Role “'.SystemRole::labelFor($role->name).'” created.');
    }

    public function edit(Request $request, Role $adminRole): View
    {
        $actor = $request->user('admin');

        return view('admin.roles.edit', $this->formData($adminRole, $adminRole->permissions->pluck('name')->all()) + [
            'readOnlyReason' => match (true) {
                $adminRole->name === SystemRole::SuperAdmin->value => 'The Super Admin role always has every permission and cannot be changed.',
                ! $actor->can(SystemPermission::RolesUpdate->value) => 'You can view this role but not change it.',
                ! $actor->isSuperAdmin() && $actor->hasRole($adminRole) => 'You cannot change a role you hold.',
                default => null,
            },
            'canDelete' => $actor->can(SystemPermission::RolesDelete->value)
                && ! SystemRole::isBuiltIn($adminRole->name)
                && ($actor->isSuperAdmin() || ! $actor->hasRole($adminRole)),
            'staffCount' => SystemUser::role($adminRole->name, 'admin')->count(),
        ]);
    }

    public function update(RoleRequest $request, Role $adminRole, UpdateRole $update): RedirectResponse
    {
        $update->handle($adminRole, $request->roleName(), $request->permissions(), $request->user('admin'));

        return redirect()->route('admin.roles')->with('status', 'Role “'.SystemRole::labelFor($adminRole->name).'” updated.');
    }

    public function destroy(Request $request, Role $adminRole, DeleteRole $delete): RedirectResponse
    {
        $label = SystemRole::labelFor($adminRole->name);
        $delete->handle($adminRole, $request->user('admin'));

        return redirect()->route('admin.roles')->with('status', "Role “{$label}” deleted.");
    }

    /**
     * @param  list<string>  $granted
     * @return array<string, mixed>
     */
    private function formData(?Role $role, array $granted): array
    {
        return [
            'role' => $role,
            'granted' => $role?->name === SystemRole::SuperAdmin->value ? SystemPermission::values() : $granted,
            'modules' => collect(SystemPermission::byModule())
                ->map(fn (array $permissions, string $module) => [
                    'module' => PermissionModule::from($module),
                    'permissions' => $permissions,
                ])
                ->values(),
            'nameEditable' => $role === null || ! SystemRole::isBuiltIn($role->name),
        ];
    }
}
