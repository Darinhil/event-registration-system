<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One timesheet per person per month. Day entries are stored as JSON keyed by day number:
        // { "7": { "hours": 6.5, "leave_type": null, "leave_part": null, "note": "", "attachment": null } }
        Schema::create('timesheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('draft'); // draft | submitted | returned | approved
            $table->json('entries')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();          // employee's note to the approver
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('return_comment')->nullable();   // approver's reason when returning it
            $table->json('day_comments')->nullable();     // approver's comments on specific days
            $table->timestamp('processed_at')->nullable(); // HR has processed an approved timesheet
            $table->json('history')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'year', 'month']);
            $table->index(['approver_id', 'status']);
        });

        // Days nobody is expected to work. Maintained by HR; none are assumed.
        Schema::create('public_holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 150);
            $table->timestamps();
        });

        // A person's leave allowance for a year. Without a row, the defaults in TimesheetController apply.
        Schema::create('leave_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('leave_type', 30);
            $table->decimal('entitled_days', 5, 1)->default(0);
            $table->decimal('carried_over_days', 5, 1)->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'year', 'leave_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_entitlements');
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('timesheets');
    }
};
