<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FormBuilderController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\AccessControlSettingsController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/admin/login', [AuthController::class, 'adminLogin']);
Route::get('/public-settings', [SystemSettingsController::class, 'publicShow']);
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{event}/banner', [EventController::class, 'banner']);
Route::get('/events/{event}', [EventController::class, 'show']);
Route::get('/events/{event}/form', [EventController::class, 'form']);
Route::get('/registrations/{registration}/qr', [RegistrationController::class, 'qr']);

Route::middleware(['auth:sanctum', 'active-account'])->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [UserController::class, 'show']);
    Route::get('/me/profile-photo', [UserController::class, 'profilePhoto']);
    Route::post('/me/profile', [UserController::class, 'updateProfile']);
    Route::patch('/me/password', [UserController::class, 'updatePassword']);
    Route::post('/registrations', [RegistrationController::class, 'store']);
    Route::get('/registrations/{registration}', [RegistrationController::class, 'show']);
    Route::put('/registrations/{registration}', [RegistrationController::class, 'update']);
    Route::delete('/registrations/{registration}', [RegistrationController::class, 'destroy']);
    Route::get('/check-ins/lookup', [CheckInController::class, 'lookup']);
    Route::post('/check-ins', [CheckInController::class, 'store']);
    Route::post('/check-ins/preview', [CheckInController::class, 'preview']);

    Route::middleware('can:manage-events')->prefix('admin')->group(function (): void {
        Route::post('/check-ins/lookup', [CheckInController::class, 'lookup'])->middleware('can:checkins.manage');
        Route::post('/check-ins/search', [CheckInController::class, 'search'])->middleware('can:checkins.manage');
        Route::get('/events', [EventController::class, 'adminIndex'])->middleware('can:events.view');
        Route::get('/events/{event}/banner', [EventController::class, 'banner'])->middleware('can:view,event');
        Route::post('/events', [EventController::class, 'store'])->middleware('can:events.manage');
        Route::put('/events/{event}', [EventController::class, 'update'])->middleware(['can:events.manage', 'can:update,event']);
        Route::get('/events/{event}/registrants', [EventController::class, 'registrants'])->middleware('can:view,event');
        Route::post('/events/{event}/check-in-qr', [EventController::class, 'regenerateCheckInQr'])->middleware(['can:checkins.manage', 'can:update,event']);
        Route::get('/events/{event}/form-builder', [FormBuilderController::class, 'show'])->middleware(['can:events.view', 'can:view,event']);
        Route::put('/events/{event}/form-builder', [FormBuilderController::class, 'update'])->middleware(['can:forms.create', 'can:update,event']);
        Route::patch('/events/{event}/form-builder/publish', [FormBuilderController::class, 'publish'])->middleware(['can:forms.approve', 'can:update,event']);
        Route::patch('/events/{event}/close', [EventController::class, 'close'])->middleware(['can:events.manage', 'can:update,event']);
        Route::patch('/events/{event}/cancel', [EventController::class, 'cancel'])->middleware(['can:events.manage', 'can:update,event']);
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->middleware(['can:events.manage', 'can:delete,event']);
        Route::get('/dashboard', [AdminController::class, 'dashboard'])->middleware('can:events.view');
        Route::get('/reports', [AdminController::class, 'reports'])->middleware('can:reports.view');
        Route::get('/events/{event}/report-attendees', [AdminController::class, 'eventReportAttendees'])->middleware(['can:reports.view', 'can:view,event']);
        Route::get('/users', [AdminController::class, 'users'])->middleware('can:users.manage');
        Route::get('/check-ins', [AdminController::class, 'checkIns'])->middleware('can:checkins.manage');
        Route::get('/events/{event}/check-ins', [AdminController::class, 'eventCheckIns'])->middleware(['can:checkins.manage', 'can:view,event']);
        Route::middleware('can:manage-admin-accounts')->group(function (): void {
            Route::get('/settings', [SystemSettingsController::class, 'show']);
            Route::put('/settings', [SystemSettingsController::class, 'update']);
        });
        Route::middleware('can:manage-access-control')->prefix('settings/access-control')->group(function (): void {
            Route::get('/', [AccessControlSettingsController::class, 'index']);
            Route::put('/roles/{role}/permissions', [AccessControlSettingsController::class, 'updateRolePermissions'])->middleware('can:roles.manage');
            Route::post('/staff', [AccessControlSettingsController::class, 'storeStaff'])->middleware('can:users.manage');
            Route::put('/staff/{staff}', [AccessControlSettingsController::class, 'updateStaff'])->middleware('can:users.manage');
            Route::put('/users/{user}/status', [AccessControlSettingsController::class, 'updateUserStatus'])->middleware('can:users.manage');
            Route::post('/workflows', [AccessControlSettingsController::class, 'storeWorkflow'])->middleware('can:workflows.manage');
            Route::put('/workflows/{workflow}', [AccessControlSettingsController::class, 'updateWorkflow'])->middleware('can:workflows.manage');
            Route::delete('/workflows/{workflow}', [AccessControlSettingsController::class, 'destroyWorkflow'])->middleware('can:workflows.manage');
        });
        Route::get('/admin-accounts', [AdminController::class, 'adminAccounts'])->middleware('can:manage-users');
        Route::post('/admin-accounts', [AdminController::class, 'createAdminAccount'])->middleware('can:create-users');
        Route::put('/admin-accounts/{user}', [AdminController::class, 'updateAdminAccount'])->middleware('can:users.manage');
        Route::delete('/admin-accounts/{user}', [AdminController::class, 'deleteAdminAccount'])->middleware('can:users.manage');
    });
});
