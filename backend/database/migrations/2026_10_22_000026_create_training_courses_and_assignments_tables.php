<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_courses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 180);
            $table->string('category', 120);
            $table->string('description', 1000);
            $table->longText('content');
            $table->json('quiz');
            $table->date('due_date')->nullable();
            $table->unsignedTinyInteger('pass_percent')->default(80);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('training_course_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('Pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('score')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['training_course_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_course_assignments');
        Schema::dropIfExists('training_courses');
    }
};
