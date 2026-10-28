<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\FinanceVoucherController;
use App\Http\Controllers\FormBuilderController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\TimesheetController;
use App\Http\Controllers\TrainingController;
use Illuminate\Support\Facades\Route;

// Throttled per IP to slow down password guessing and account spam.
Route::middleware('throttle:10,1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/admin/login', [AuthController::class, 'adminLogin']);
});
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{event}/banner', [EventController::class, 'banner']);
Route::get('/events/{event}', [EventController::class, 'show']);
Route::get('/events/{event}/form', [EventController::class, 'form']);
Route::get('/settings', [SystemSettingsController::class, 'show']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [UserController::class, 'show']);
    Route::get('/me/profile-photo', [UserController::class, 'profilePhoto']);
    Route::post('/me/profile', [UserController::class, 'updateProfile']);
    Route::patch('/me/password', [UserController::class, 'updatePassword'])->middleware('throttle:10,1');
    Route::post('/registrations', [RegistrationController::class, 'store']);
    Route::get('/registrations/{registration}', [RegistrationController::class, 'show']);
    Route::get('/registrations/{registration}/qr', [RegistrationController::class, 'qr']);
    Route::put('/registrations/{registration}', [RegistrationController::class, 'update']);
    Route::delete('/registrations/{registration}', [RegistrationController::class, 'destroy']);
    Route::post('/check-ins', [CheckInController::class, 'store']);
    Route::post('/check-ins/preview', [CheckInController::class, 'preview']);

    // Personnel portal: a staff member's own compliance requirements.
    Route::middleware('can:use-personnel-portal')->group(function (): void {
        Route::get('/me/compliances', [ComplianceController::class, 'index']);
        Route::post('/me/compliances/{policy}/complete', [ComplianceController::class, 'complete'])->middleware('throttle:30,1');
        Route::get('/me/training', [TrainingController::class, 'index']);
        Route::get('/me/training/{course}', [TrainingController::class, 'show']);
        Route::post('/me/training/{course}/lessons/{lesson}/complete', [TrainingController::class, 'completeLesson']);
        Route::get('/me/training/{course}/quiz', [TrainingController::class, 'quiz']);
        Route::post('/me/training/{course}/quiz', [TrainingController::class, 'submitQuiz'])->middleware('throttle:20,1');
        Route::get('/me/overtime', [OvertimeController::class, 'index']);
        Route::post('/me/overtime', [OvertimeController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/me/overtime/supervisors', [OvertimeController::class, 'supervisors']);
        Route::get('/me/overtime/approvals', [OvertimeController::class, 'approvals']);
        Route::post('/overtime/{overtime}/cancel', [OvertimeController::class, 'cancel']);
        Route::post('/overtime/{overtime}/decision', [OvertimeController::class, 'decide']);
        Route::prefix('/me/timesheets/{year}/{month}')->whereNumber(['year', 'month'])->group(function (): void {
            Route::get('/', [TimesheetController::class, 'show']);
            Route::put('/', [TimesheetController::class, 'save'])->middleware('throttle:60,1');
            Route::post('/submit', [TimesheetController::class, 'submit']);
            Route::post('/recall', [TimesheetController::class, 'recall']);
            Route::post('/attachments', [TimesheetController::class, 'uploadAttachment'])->middleware('throttle:30,1');
        });
        Route::get('/me/timesheet-approvals', [TimesheetController::class, 'approvals']);
        Route::get('/timesheets/{timesheet}', [TimesheetController::class, 'review']);
        Route::get('/timesheets/{timesheet}/attachments/{day}', [TimesheetController::class, 'attachment'])->whereNumber('day');
        Route::post('/timesheets/{timesheet}/decision', [TimesheetController::class, 'decide']);
        Route::post('/timesheets/{timesheet}/process', [TimesheetController::class, 'process']);
        Route::get('/me/contracts', [ContractController::class, 'mine']);
        // The owner or a Master Admin; checked in the controller.
        Route::get('/contracts/{contract}/file', [ContractController::class, 'file']);
    });

    Route::middleware('can:manage-events')->prefix('admin')->group(function (): void {
        Route::match(['get', 'post'], '/check-ins/lookup', [CheckInController::class, 'lookup']);
        Route::post('/check-ins/search', [CheckInController::class, 'search']);
        Route::get('/events', [EventController::class, 'adminIndex']);
        Route::get('/events/{event}/banner', [EventController::class, 'banner'])->middleware('can:view,event');
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
        Route::get('/finance-vouchers', [FinanceVoucherController::class, 'index']);
        Route::post('/finance-vouchers', [FinanceVoucherController::class, 'store']);
        Route::get('/finance-vouchers/{financeVoucher}', [FinanceVoucherController::class, 'show']);
        Route::put('/finance-vouchers/{financeVoucher}', [FinanceVoucherController::class, 'update']);
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/notifications', [AdminController::class, 'notifications']);
        Route::get('/reports', [AdminController::class, 'reports']);
        Route::get('/events/{event}/report-attendees', [AdminController::class, 'eventReportAttendees'])->middleware('can:view,event');
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/check-ins', [AdminController::class, 'checkIns']);
        Route::get('/events/{event}/check-ins', [AdminController::class, 'eventCheckIns'])->middleware('can:view,event');
        Route::middleware('can:manage-admin-accounts')->group(function (): void {
            Route::get('/settings', [SystemSettingsController::class, 'show']);
            Route::put('/settings', [SystemSettingsController::class, 'update']);
            Route::get('/contracts', [ContractController::class, 'index']);
            Route::post('/contracts', [ContractController::class, 'store']);
            Route::post('/contracts/{contract}', [ContractController::class, 'update']);
            Route::delete('/contracts/{contract}', [ContractController::class, 'destroy']);
            Route::get('/admin-accounts', [AdminController::class, 'adminAccounts']);
            Route::post('/admin-accounts', [AdminController::class, 'createAdminAccount']);
            Route::put('/admin-accounts/{user}', [AdminController::class, 'updateAdminAccount']);
            Route::delete('/admin-accounts/{user}', [AdminController::class, 'deleteAdminAccount']);
        });
    });
});
