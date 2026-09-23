<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FormBuilderController;
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
    Route::post('/check-ins', [CheckInController::class, 'store']);

    Route::middleware('can:manage-events')->prefix('admin')->group(function (): void {
        Route::get('/events', [EventController::class, 'adminIndex']);
        Route::post('/events', [EventController::class, 'store']);
        Route::put('/events/{event}', [EventController::class, 'update']);
        Route::get('/events/{event}/registrants', [EventController::class, 'registrants']);
        Route::get('/events/{event}/form-builder', [FormBuilderController::class, 'show']);
        Route::put('/events/{event}/form-builder', [FormBuilderController::class, 'update']);
        Route::patch('/events/{event}/form-builder/publish', [FormBuilderController::class, 'publish']);
        Route::patch('/events/{event}/close', [EventController::class, 'close']);
        Route::patch('/events/{event}/cancel', [EventController::class, 'cancel']);
        Route::delete('/events/{event}', [EventController::class, 'destroy']);
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/check-ins', [AdminController::class, 'checkIns']);
    });
});
