<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SystemUsers\ChangeSystemUserStatus;
use App\Actions\Admin\SystemUsers\CreateSystemUser;
use App\Actions\Admin\SystemUsers\DeleteSystemUser;
use App\Actions\Admin\SystemUsers\UpdateSystemUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SystemUsers\StoreSystemUserRequest;
use App\Http\Requests\Admin\SystemUsers\UpdateSystemUserRequest;
use App\Models\SystemUser;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Staff (System User) management. Routes require system-users.manage; the
 * actions re-check it and enforce the safety rules (see SystemUserRules).
 */
class SystemUserController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'admin')],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
        ]);

        $staff = SystemUser::query()
            ->with('roles')
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like));
            })
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->role($role, 'admin'))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.system-users.index', [
            'staff' => $staff,
            'filters' => $filters,
            'roles' => $this->roleOptions(),
            'actor' => $request->user('admin'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.system-users.create', [
            'roles' => $this->assignableRoles($request->user('admin')),
        ]);
    }

    public function store(StoreSystemUserRequest $request, CreateSystemUser $create): RedirectResponse
    {
        $staff = $create->handle($request->validated(), $request->user('admin'));

        return redirect()->route('admin.system-users')->with('status', "Staff account for {$staff->name} created.");
    }

    public function edit(Request $request, SystemUser $systemUser): View
    {
        return view('admin.system-users.edit', [
            'staff' => $systemUser,
            'roles' => $this->assignableRoles($request->user('admin')),
            'isSelf' => $request->user('admin')->is($systemUser),
        ]);
    }

    public function update(UpdateSystemUserRequest $request, SystemUser $systemUser, UpdateSystemUser $update): RedirectResponse
    {
        $update->handle($systemUser, $request->validated(), $request->user('admin'));

        return redirect()->route('admin.system-users')->with('status', "{$systemUser->name} updated.");
    }

    public function updateStatus(Request $request, SystemUser $systemUser, ChangeSystemUserStatus $change): RedirectResponse
    {
        $status = $request->validate(['status' => ['required', Rule::enum(UserStatus::class)]])['status'];

        $change->handle($systemUser, UserStatus::from($status), $request->user('admin'));

        $verb = $status === UserStatus::Active->value ? 'activated' : 'deactivated';

        return back()->with('status', "{$systemUser->name} {$verb}.");
    }

    public function destroy(Request $request, SystemUser $systemUser, DeleteSystemUser $delete): RedirectResponse
    {
        $delete->handle($systemUser, $request->user('admin'));

        return redirect()->route('admin.system-users')->with('status', "{$systemUser->name} deleted.");
    }

    /**
     * Staff roles (built-in first, then custom) as name => label.
     *
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        $builtIn = SystemRole::values();

        return Role::where('guard_name', 'admin')->pluck('name')
            ->sortBy(fn (string $name) => [in_array($name, $builtIn, true) ? 0 : 1, array_search($name, $builtIn, true), mb_strtolower($name)])
            ->mapWithKeys(fn (string $name) => [$name => SystemRole::labelFor($name)])
            ->all();
    }

    /**
     * Super Admin can be granted only by a Super Admin.
     *
     * @return array<string, string>
     */
    private function assignableRoles(SystemUser $actor): array
    {
        return array_filter(
            $this->roleOptions(),
            fn (string $name) => $name !== SystemRole::SuperAdmin->value || $actor->isSuperAdmin(),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
