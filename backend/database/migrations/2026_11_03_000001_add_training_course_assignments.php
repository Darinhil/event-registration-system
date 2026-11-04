<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table): void {
            $table->boolean('assign_all_staff')->default(true);
        });

        Schema::create('training_course_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['training_course_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_course_user');
        Schema::table('training_courses', function (Blueprint $table): void {
            $table->dropColumn('assign_all_staff');
        });
    }
};
