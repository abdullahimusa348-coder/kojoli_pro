<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Customers\ChangeCustomerStatus;
use App\Actions\Customers\ChangeUserType;
use App\Actions\Customers\SendCustomerPasswordReset;
use App\Actions\Customers\UpdateCustomerProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customers\UpdateCustomerRequest;
use App\Models\User;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer accounts in the admin area ("Users"). Routes enforce the
 * customers.* permissions; each action re-checks its permission. There is no
 * delete: customers are disabled instead so future financial records stay intact.
 */
class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(UserType::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
        ]);

        $customers = User::query()
            ->when($filters['q'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $digits = preg_replace('/\D+/', '', $term);
                $query->where(function ($q) use ($term, $like, $digits) {
                    $q->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                    if (strlen($digits) >= 4) {
                        // Phones are stored as 0XXXXXXXXXX; match "+234 803 111" style input too, even partial.
                        $local = str_starts_with($digits, '234') ? '0'.substr($digits, 3) : $digits;
                        $q->orWhere('phone', 'like', '%'.$local.'%');
                    }
                    if (ctype_digit($term)) {
                        $q->orWhere('id', (int) $term);
                    }
                });
            })
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('user_type', $type))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', [
            'customers' => $customers,
            'filters' => $filters,
            'types' => UserType::cases(),
        ]);
    }

    public function show(Request $request, User $customer): View
    {
        $staff = $request->user('admin');

        return view('admin.users.show', [
            'customer' => $customer,
            'activeTokens' => $customer->tokens()->count(),
            'types' => UserType::cases(),
            'can' => [
                'update' => $staff->can(SystemPermission::CustomersUpdate->value),
                'status' => $staff->can(SystemPermission::CustomersUpdateStatus->value),
                'type' => $staff->can(SystemPermission::CustomersChangeType->value),
                'reset' => $staff->can(SystemPermission::CustomersResetPassword->value),
            ],
        ]);
    }

    public function edit(User $customer): View
    {
        return view('admin.users.edit', ['customer' => $customer]);
    }

    public function update(UpdateCustomerRequest $request, User $customer, UpdateCustomerProfile $update): RedirectResponse
    {
        $update->handle($customer, $request->validated(), $request->user('admin'));

        return redirect()->route('admin.users.show', $customer)->with('status', 'Customer details updated.');
    }

    public function updateStatus(Request $request, User $customer, ChangeCustomerStatus $change): RedirectResponse
    {
        $status = UserStatus::from($request->validate(['status' => ['required', Rule::enum(UserStatus::class)]])['status']);

        $change->handle($customer, $status, $request->user('admin'));

        return back()->with('status', $status === UserStatus::Disabled
            ? 'Customer disabled. Their API tokens were revoked and they are signed out.'
            : 'Customer enabled.');
    }

    public function updateType(Request $request, User $customer, ChangeUserType $change): RedirectResponse
    {
        $type = UserType::from($request->validate(['user_type' => ['required', Rule::enum(UserType::class)]])['user_type']);

        $change->handle($customer, $type, $request->user('admin'));

        return back()->with('status', "Customer type changed to {$type->label()}.");
    }

    public function sendPasswordReset(Request $request, User $customer, SendCustomerPasswordReset $send): RedirectResponse
    {
        $send->handle($customer, $request->user('admin'));

        return back()->with('status', "Password reset link sent to {$customer->email}.");
    }
}
