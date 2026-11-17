<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('budget_number', 80)->index();
            $table->string('department', 80);
            $table->string('project_name', 150);
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->string('workflow_status', 24)->default('draft')->index();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_budgets');
    }
};
