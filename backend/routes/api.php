<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FormBuilderController;
use App\Http\Controllers\SystemSettingsController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/admin/login', [AuthController::class, 'adminLogin']);
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{event}', [EventController::class, 'show']);
Route::get('/events/{event}/form', [EventController::class, 'form']);
Route::get('/registrations/{registration}/qr', [RegistrationController::class, 'qr']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [UserController::class, 'show']);
    Route::get('/me/profile-photo', [UserController::class, 'profilePhoto']);
    Route::post('/me/profile', [UserController::class, 'updateProfile']);
    Route::patch('/me/password', [UserController::class, 'updatePassword']);
    Route::post('/registrations', [RegistrationController::class, 'store']);
    Route::get('/registrations/{registration}', [RegistrationController::class, 'show']);
    Route::put('/registrations/{registration}', [RegistrationController::class, 'update']);
    Route::get('/check-ins/lookup', [CheckInController::class, 'lookup']);
    Route::post('/check-ins', [CheckInController::class, 'store']);
    Route::post('/check-ins/preview', [CheckInController::class, 'preview']);

    Route::middleware('can:manage-events')->prefix('admin')->group(function (): void {
        Route::post('/check-ins/lookup', [CheckInController::class, 'lookup']);
        Route::post('/check-ins/search', [CheckInController::class, 'search']);
        Route::get('/events', [EventController::class, 'adminIndex']);
        Route::post('/events', [EventController::class, 'store']);
        Route::put('/events/{event}', [EventController::class, 'update'])->middleware('can:update,event');
        Route::get('/events/{event}/registrants', [EventController::class, 'registrants'])->middleware('can:view,event');
        Route::post('/events/{event}/check-in-qr', [EventController::class, 'regenerateCheckInQr'])->middleware('can:update,event');
        Route::get('/events/{event}/form-builder', [FormBuilderController::class, 'show'])->middleware('can:view,event');
        Route::put('/events/{event}/form-builder', [FormBuilderController::class, 'update'])->middleware('can:update,event');
        Route::patch('/events/{event}/form-builder/publish', [FormBuilderController::class, 'publish'])->middleware('can:update,event');
        Route::patch('/events/{event}/close', [EventController::class, 'close'])->middleware('can:update,event');
        Route::patch('/events/{event}/cancel', [EventController::class, 'cancel'])->middleware('can:update,event');
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->middleware('can:delete,event');
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::get('/events/{event}/report-attendees', [AdminController::class, 'eventReportAttendees'])->middleware('can:view,event');
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/check-ins', [AdminController::class, 'checkIns']);
        Route::get('/events/{event}/check-ins', [AdminController::class, 'eventCheckIns'])->middleware('can:view,event');
        Route::middleware('can:manage-admin-accounts')->group(function (): void {
            Route::get('/settings', [SystemSettingsController::class, 'show']);
            Route::put('/settings', [SystemSettingsController::class, 'update']);
            Route::get('/admin-accounts', [AdminController::class, 'adminAccounts']);
            Route::post('/admin-accounts', [AdminController::class, 'createAdminAccount']);
            Route::put('/admin-accounts/{user}', [AdminController::class, 'updateAdminAccount']);
            Route::delete('/admin-accounts/{user}', [AdminController::class, 'deleteAdminAccount']);
        });
    });
});
