<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Overtime a staff member asks their supervisor to approve.
        // start_at/end_at are full datetimes so overnight shifts and overlap checks are exact.
        Schema::create('overtime_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->restrictOnDelete();
            $table->date('work_date');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->unsignedSmallInteger('minutes');
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'start_at']);
            $table->index(['supervisor_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('overtime_requests'); }
};
