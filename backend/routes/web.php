<?php

use App\Http\Controllers\Admin\Auth\AdminSessionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ModulePlaceholderController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SystemUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\PasswordController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\SecurityController;
use App\Support\Admin\AdminModule;
use App\Support\Enums\SystemPermission;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Guest (customer) authentication
Route::middleware('guest:web')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])->middleware('throttle:10,1');

    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

// Authenticated customer area
Route::middleware(['auth:web', 'auth.session'])->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Email verification (enforced only when nadabo.require_email_verification is on)
    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Profile stays reachable while unverified, so a mistyped email can be corrected.
    Route::get('dashboard', DashboardController::class)->middleware('verified.optional')->name('dashboard');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [PasswordController::class, 'update'])->name('profile.password.update');

    // Security: own browser sessions and app tokens. Reachable while unverified, like the Account page.
    Route::get('security', [SecurityController::class, 'show'])->name('security');
    Route::middleware('throttle:10,1')->group(function () {
        Route::delete('security/sessions/{session}', [SecurityController::class, 'destroySession'])
            ->where('session', '[a-f0-9]{64}')
            ->name('security.sessions.destroy');
        Route::post('security/sessions/logout-others', [SecurityController::class, 'logoutOtherSessions'])
            ->name('security.sessions.logout-others');
        Route::delete('security/tokens/{token}', [SecurityController::class, 'destroyToken'])
            ->whereNumber('token')
            ->name('security.tokens.destroy');
        Route::delete('security/tokens', [SecurityController::class, 'destroyAllTokens'])
            ->name('security.tokens.destroy-all');
    });
});

// Admin area: staff only (system_users, `admin` guard, separate session cookie).
// Customer sessions are never read here. Each page is guarded by the same
// permission that shows it in the sidebar (App\Support\Admin\AdminModule).
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminSessionController::class, 'create'])->name('login');
        Route::post('login', [AdminSessionController::class, 'store'])->name('login.store');
    });

    Route::middleware(['auth:admin', 'staff.active'])->group(function () {
        Route::post('logout', [AdminSessionController::class, 'destroy'])->name('logout');

        Route::get('/', AdminDashboardController::class)
            ->middleware('permission:'.AdminModule::Dashboard->permission().',admin')
            ->name('dashboard');

        // Settings store
        Route::middleware('permission:'.SystemPermission::AdminAccess->value.',admin')->group(function () {
            Route::get('settings', [SettingsController::class, 'index'])
                ->middleware('permission:'.SystemPermission::SettingsView->value.',admin')
                ->name('settings');
            Route::put('settings', [SettingsController::class, 'update'])
                ->middleware([
                    'permission:'.SystemPermission::SettingsView->value.',admin',
                    'permission:'.SystemPermission::SettingsUpdate->value.',admin',
                ])
                ->name('settings.update');
        });

        // System Users (staff accounts)
        Route::middleware([
            'permission:'.SystemPermission::AdminAccess->value.',admin',
            'permission:'.SystemPermission::SystemUsersManage->value.',admin',
        ])->prefix('system-users')->name('system-users')->group(function () {
            Route::get('/', [SystemUserController::class, 'index']);
            Route::get('create', [SystemUserController::class, 'create'])->name('.create');
            Route::post('/', [SystemUserController::class, 'store'])->name('.store');
            Route::get('{systemUser}/edit', [SystemUserController::class, 'edit'])->name('.edit');
            Route::put('{systemUser}', [SystemUserController::class, 'update'])->name('.update');
            Route::patch('{systemUser}/status', [SystemUserController::class, 'updateStatus'])->name('.status');
            Route::delete('{systemUser}', [SystemUserController::class, 'destroy'])->name('.destroy');
        });

        // Roles & Permissions
        Route::middleware(SystemPermission::AdminAccess->middleware())->prefix('roles')->name('roles')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->middleware(SystemPermission::RolesView->middleware());
            Route::get('create', [RoleController::class, 'create'])->middleware(SystemPermission::RolesCreate->middleware())->name('.create');
            Route::post('/', [RoleController::class, 'store'])->middleware(SystemPermission::RolesCreate->middleware())->name('.store');
            Route::get('{adminRole}/edit', [RoleController::class, 'edit'])->middleware(SystemPermission::RolesView->middleware())->name('.edit');
            Route::put('{adminRole}', [RoleController::class, 'update'])->middleware(SystemPermission::RolesUpdate->middleware())->name('.update');
            Route::delete('{adminRole}', [RoleController::class, 'destroy'])->middleware(SystemPermission::RolesDelete->middleware())->name('.destroy');
        });

        // Users (customer accounts). No delete route: customers are disabled, never deleted.
        Route::middleware([SystemPermission::AdminAccess->middleware(), SystemPermission::CustomersView->middleware()])
            ->prefix('users')->name('users')->group(function () {
                Route::get('/', [CustomerController::class, 'index']);
                Route::get('{customer}', [CustomerController::class, 'show'])->name('.show');
                Route::get('{customer}/edit', [CustomerController::class, 'edit'])->middleware(SystemPermission::CustomersUpdate->middleware())->name('.edit');
                Route::put('{customer}', [CustomerController::class, 'update'])->middleware(SystemPermission::CustomersUpdate->middleware())->name('.update');
                Route::patch('{customer}/status', [CustomerController::class, 'updateStatus'])->middleware(SystemPermission::CustomersUpdateStatus->middleware())->name('.status');
                Route::patch('{customer}/type', [CustomerController::class, 'updateType'])->middleware(SystemPermission::CustomersChangeType->middleware())->name('.type');
                Route::post('{customer}/password-reset', [CustomerController::class, 'sendPasswordReset'])
                    ->middleware([SystemPermission::CustomersResetPassword->middleware(), 'throttle:10,1'])
                    ->name('.password-reset');
            });

        // Service catalog: categories, services, products and plans (structure only;
        // services.* covers all four). No delete routes.
        Route::middleware([SystemPermission::AdminAccess->middleware(), SystemPermission::ServicesView->middleware()])
            ->prefix('services')->name('services')->group(function () {
                // Categories tab (registered before {service} routes)
                Route::get('categories', [ServiceCategoryController::class, 'index'])->name('.categories');
                Route::get('categories/create', [ServiceCategoryController::class, 'create'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.categories.create');
                Route::post('categories', [ServiceCategoryController::class, 'store'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.categories.store');
                Route::get('categories/{category}', [ServiceCategoryController::class, 'show'])->whereNumber('category')->name('.categories.show');
                Route::get('categories/{category}/edit', [ServiceCategoryController::class, 'edit'])->whereNumber('category')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.categories.edit');
                Route::put('categories/{category}', [ServiceCategoryController::class, 'update'])->whereNumber('category')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.categories.update');
                Route::patch('categories/{category}/status', [ServiceCategoryController::class, 'updateStatus'])->whereNumber('category')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.categories.status');

                // Products tab
                Route::get('products', [ProductController::class, 'index'])->name('.products');
                Route::get('products/create', [ProductController::class, 'create'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.products.create');
                Route::post('products', [ProductController::class, 'store'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.products.store');
                Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('.products.show');
                Route::get('products/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.products.edit');
                Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.products.update');
                Route::patch('products/{product}/status', [ProductController::class, 'updateStatus'])->whereNumber('product')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.products.status');

                // Plans tab
                Route::get('plans', [PlanController::class, 'index'])->name('.plans');
                Route::get('plans/create', [PlanController::class, 'create'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.plans.create');
                Route::post('plans', [PlanController::class, 'store'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.plans.store');
                Route::get('plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan')->name('.plans.show');
                Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->whereNumber('plan')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.plans.edit');
                Route::put('plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.plans.update');
                Route::patch('plans/{plan}/status', [PlanController::class, 'updateStatus'])->whereNumber('plan')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.plans.status');

                // Services tab
                Route::get('/', [ServiceController::class, 'index']);
                Route::get('create', [ServiceController::class, 'create'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.create');
                Route::post('/', [ServiceController::class, 'store'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.store');
                Route::get('{service}', [ServiceController::class, 'show'])->whereNumber('service')->name('.show');
                Route::get('{service}/edit', [ServiceController::class, 'edit'])->whereNumber('service')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.edit');
                Route::put('{service}', [ServiceController::class, 'update'])->whereNumber('service')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.update');
                Route::patch('{service}/status', [ServiceController::class, 'updateStatus'])->whereNumber('service')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.status');
            });

        // Modules not built yet: navigation placeholders only, no business logic.
        foreach (AdminModule::cases() as $module) {
            if ($module->isBuilt()) {
                continue;
            }

            Route::get($module->path(), ModulePlaceholderController::class)
                ->defaults('module', $module->value)
                ->middleware(['permission:'.AdminModule::Dashboard->permission().',admin', 'permission:'.$module->permission().',admin'])
                ->name($module->value);
        }
    });
});
