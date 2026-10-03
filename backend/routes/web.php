<?php

use App\Http\Controllers\Admin\Auth\AdminSessionController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\ModulePlaceholderController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\PasswordController;
use App\Http\Controllers\User\ProfileController;
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
