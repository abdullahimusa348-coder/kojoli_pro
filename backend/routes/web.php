<?php

use App\Http\Controllers\Admin\Auth\AdminSessionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ModulePlaceholderController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentGatewayController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlanPriceController;
use App\Http\Controllers\Admin\PlanRouteController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProviderBulkRouteController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\ProviderCredentialController;
use App\Http\Controllers\Admin\ProviderServiceController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SystemUserController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\WalletController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\FundWalletController;
use App\Http\Controllers\User\PasswordController;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\User\SecurityController;
use App\Http\Controllers\User\WalletController as CustomerWalletController;
use App\Support\Admin\AdminModule;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\UserType;
use App\Support\Providers\CredentialKey;
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
    // The customer's own wallet. Funding goes through a payment gateway and is credited only
    // after server-side verification. No withdrawals, purchases or transfers.
    Route::get('wallet', CustomerWalletController::class)->middleware('verified.optional')->name('wallet');
    Route::middleware('verified.optional')->prefix('wallet/fund')->name('wallet.fund')->group(function () {
        Route::get('/', [FundWalletController::class, 'create']);
        Route::post('/', [FundWalletController::class, 'store'])->middleware('throttle:10,1')->name('.store');
        Route::get('{reference}', [FundWalletController::class, 'show'])->where('reference', 'PAY-[0-9A-Z]{26}')
            ->middleware('throttle:30,1')->name('.show');
    });

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

        // Service catalog: categories, services, products and plans (services.* covers
        // all four) plus plan prices (pricing.*). No delete routes.
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

                // Plan prices (customer selling prices): services.view + pricing.view; changes need pricing.update. No delete.
                Route::middleware(SystemPermission::PricingView->middleware())->group(function () {
                    Route::get('plans/{plan}/prices', [PlanPriceController::class, 'show'])->whereNumber('plan')->name('.plans.prices');
                    Route::get('plans/{plan}/prices/edit', [PlanPriceController::class, 'edit'])->whereNumber('plan')->middleware(SystemPermission::PricingUpdate->middleware())->name('.plans.prices.edit');
                    Route::put('plans/{plan}/prices', [PlanPriceController::class, 'update'])->whereNumber('plan')->middleware(SystemPermission::PricingUpdate->middleware())->name('.plans.prices.update');
                    Route::patch('plans/{plan}/prices/{userType}/status', [PlanPriceController::class, 'updateStatus'])->whereNumber('plan')->whereIn('userType', UserType::values())
                        ->middleware(SystemPermission::PricingUpdate->middleware())->name('.plans.prices.status');
                });

                // Plan provider routes: services.view + providers.view; changes need providers.update. No delete.
                Route::middleware(SystemPermission::ProvidersView->middleware())->group(function () {
                    Route::get('plans/{plan}/routes', [PlanRouteController::class, 'index'])->whereNumber('plan')->name('.plans.routes');
                    Route::middleware(SystemPermission::ProvidersUpdate->middleware())->group(function () {
                        Route::post('plans/{plan}/routes', [PlanRouteController::class, 'store'])->whereNumber('plan')->name('.plans.routes.store');
                        Route::get('plans/{plan}/routes/{route}/edit', [PlanRouteController::class, 'edit'])->whereNumber(['plan', 'route'])->name('.plans.routes.edit');
                        Route::put('plans/{plan}/routes/{route}', [PlanRouteController::class, 'update'])->whereNumber(['plan', 'route'])->name('.plans.routes.update');
                        Route::patch('plans/{plan}/routes/{route}/status', [PlanRouteController::class, 'updateStatus'])->whereNumber(['plan', 'route'])->name('.plans.routes.status');
                        Route::patch('plans/{plan}/routes/{route}/move', [PlanRouteController::class, 'move'])->whereNumber(['plan', 'route'])->name('.plans.routes.move');
                    });
                });

                // Services tab
                Route::get('/', [ServiceController::class, 'index']);
                Route::get('create', [ServiceController::class, 'create'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.create');
                Route::post('/', [ServiceController::class, 'store'])->middleware(SystemPermission::ServicesCreate->middleware())->name('.store');
                Route::get('{service}', [ServiceController::class, 'show'])->whereNumber('service')->name('.show');
                Route::get('{service}/edit', [ServiceController::class, 'edit'])->whereNumber('service')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.edit');
                Route::put('{service}', [ServiceController::class, 'update'])->whereNumber('service')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.update');
                Route::patch('{service}/status', [ServiceController::class, 'updateStatus'])->whereNumber('service')->middleware(SystemPermission::ServicesUpdate->middleware())->name('.status');
            });

        // Providers (system infrastructure, independent of the catalog): providers.*.
        // Configuration only: no provider API calls, adapters or failover execution (Phase 10). No delete routes.
        Route::middleware([SystemPermission::AdminAccess->middleware(), SystemPermission::ProvidersView->middleware()])
            ->prefix('providers')->name('providers')->group(function () {
                Route::get('/', [ProviderController::class, 'index']);
                Route::get('create', [ProviderController::class, 'create'])->middleware(SystemPermission::ProvidersCreate->middleware())->name('.create');
                Route::post('/', [ProviderController::class, 'store'])->middleware(SystemPermission::ProvidersCreate->middleware())->name('.store');
                Route::get('{provider}', [ProviderController::class, 'show'])->whereNumber('provider')->name('.show');
                Route::middleware(SystemPermission::ProvidersUpdate->middleware())->group(function () {
                    Route::get('{provider}/edit', [ProviderController::class, 'edit'])->whereNumber('provider')->name('.edit');
                    Route::put('{provider}', [ProviderController::class, 'update'])->whereNumber('provider')->name('.update');
                    Route::patch('{provider}/status', [ProviderController::class, 'updateStatus'])->whereNumber('provider')->name('.status');
                    Route::post('{provider}/services', [ProviderServiceController::class, 'store'])->whereNumber('provider')->name('.services.store');
                    Route::put('{provider}/services/{capability}', [ProviderServiceController::class, 'update'])->whereNumber(['provider', 'capability'])->name('.services.update');
                    Route::patch('{provider}/services/{capability}/status', [ProviderServiceController::class, 'updateStatus'])->whereNumber(['provider', 'capability'])->name('.services.status');
                    Route::post('{provider}/bulk-routes', [ProviderBulkRouteController::class, 'store'])->whereNumber('provider')
                        ->middleware(SystemPermission::ServicesView->middleware())->name('.bulk-routes');
                });
                Route::middleware(SystemPermission::ProvidersCredentials->middleware())->group(function () {
                    Route::put('{provider}/credentials', [ProviderCredentialController::class, 'update'])->whereNumber('provider')->name('.credentials.update');
                    Route::patch('{provider}/credentials/{key}/clear', [ProviderCredentialController::class, 'clear'])->whereNumber('provider')
                        ->whereIn('key', CredentialKey::values())->name('.credentials.clear');
                });
            });

        // Customer wallets: wallet.view; adjustments and reversals need wallet.adjust,
        // freezing needs wallet.manage. No update or delete routes for ledger entries.
        Route::middleware([SystemPermission::AdminAccess->middleware(), SystemPermission::WalletView->middleware()])
            ->prefix('wallet')->name('wallet')->group(function () {
                Route::get('/', [WalletController::class, 'index']);
                Route::get('{customer}', [WalletController::class, 'show'])->whereNumber('customer')->name('.show');
                Route::post('{customer}/adjust', [WalletController::class, 'adjust'])->whereNumber('customer')
                    ->middleware([SystemPermission::WalletAdjust->middleware(), 'throttle:30,1'])->name('.adjust');
                Route::post('{customer}/transactions/{transaction}/reverse', [WalletController::class, 'reverse'])->whereNumber(['customer', 'transaction'])
                    ->middleware([SystemPermission::WalletAdjust->middleware(), 'throttle:30,1'])->name('.reverse');
                Route::patch('{customer}/status', [WalletController::class, 'updateStatus'])->whereNumber('customer')
                    ->middleware(SystemPermission::WalletManage->middleware())->name('.status');
            });

        // Payments (wallet funding): payments.view; recheck and closing a review need
        // payments.manage; gateways need payments.gateways; credentials need
        // payments.credentials. No "mark paid", refund or delete routes.
        Route::middleware([SystemPermission::AdminAccess->middleware(), SystemPermission::PaymentsView->middleware()])
            ->prefix('payments')->name('payments')->group(function () {
                Route::get('/', [PaymentController::class, 'index']);
                Route::get('gateways', [PaymentGatewayController::class, 'index'])->name('.gateways');
                Route::middleware(SystemPermission::PaymentsGateways->middleware())->group(function () {
                    Route::get('gateways/create', [PaymentGatewayController::class, 'create'])->name('.gateways.create');
                    Route::post('gateways', [PaymentGatewayController::class, 'store'])->name('.gateways.store');
                    Route::put('gateways/{gateway}', [PaymentGatewayController::class, 'update'])->whereNumber('gateway')->name('.gateways.update');
                    Route::patch('gateways/{gateway}/status', [PaymentGatewayController::class, 'updateStatus'])->whereNumber('gateway')->name('.gateways.status');
                    Route::patch('gateways/{gateway}/mode', [PaymentGatewayController::class, 'updateMode'])->whereNumber('gateway')->name('.gateways.mode');
                    Route::patch('gateways/{gateway}/move', [PaymentGatewayController::class, 'move'])->whereNumber('gateway')->name('.gateways.move');
                });
                Route::get('gateways/{gateway}', [PaymentGatewayController::class, 'show'])->whereNumber('gateway')->name('.gateways.show');
                Route::middleware(SystemPermission::PaymentsCredentials->middleware())->group(function () {
                    Route::put('gateways/{gateway}/credentials/{mode}', [PaymentGatewayController::class, 'updateCredentials'])->whereNumber('gateway')
                        ->whereIn('mode', ['sandbox', 'live'])->name('.gateways.credentials.update');
                    Route::patch('gateways/{gateway}/credentials/{mode}/{key}/clear', [PaymentGatewayController::class, 'clearCredential'])->whereNumber('gateway')
                        ->whereIn('mode', ['sandbox', 'live'])->where('key', '[a-z0-9_]{1,50}')->name('.gateways.credentials.clear');
                });
                Route::get('{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->name('.show');
                Route::middleware([SystemPermission::PaymentsManage->middleware(), 'throttle:30,1'])->group(function () {
                    Route::post('{payment}/recheck', [PaymentController::class, 'recheck'])->whereNumber('payment')->name('.recheck');
                    Route::post('{payment}/close-review', [PaymentController::class, 'closeReview'])->whereNumber('payment')->name('.close-review');
                });
            });

        // Purchases (Phase 10): purchases.view; re-check with the provider needs purchases.manage
        // (also re-checked inside the action). No mark-successful, force-fail/refund, edit or delete routes.
        Route::middleware([SystemPermission::AdminAccess->middleware(), SystemPermission::PurchasesView->middleware()])
            ->prefix('purchases')->name('purchases')->group(function () {
                Route::get('/', [PurchaseController::class, 'index']);
                Route::get('{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('.show');
                Route::post('{purchase}/recheck', [PurchaseController::class, 'recheck'])->whereNumber('purchase')
                    ->middleware([SystemPermission::PurchasesManage->middleware(), 'throttle:30,1'])->name('.recheck');
            });

        // Customer transactions (read-only in Phase 8): transactions.view.
        Route::middleware([SystemPermission::AdminAccess->middleware(), SystemPermission::TransactionsView->middleware()])
            ->prefix('transactions')->name('transactions')->group(function () {
                Route::get('/', [TransactionController::class, 'index']);
                Route::get('{transaction}', [TransactionController::class, 'show'])->whereNumber('transaction')->name('.show');
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
