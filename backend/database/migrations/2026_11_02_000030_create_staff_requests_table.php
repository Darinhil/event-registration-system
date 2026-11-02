<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Leave, travel, fuel and purchase requests, and changes to locked profile details.
        Schema::create('staff_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete(); // null: any Master Admin (HR)
            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->json('details');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['approver_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('personnel_profile')->nullable();
            $table->timestamp('personnel_profile_submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['personnel_profile', 'personnel_profile_submitted_at']);
        });
        Schema::dropIfExists('staff_requests');
    }
};
