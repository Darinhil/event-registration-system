<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('contract_type', 50);
            $table->string('position', 150);
            $table->string('department', 150)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('salary', 11, 2)->nullable();
            $table->string('status', 20)->default('Active');
            $table->timestamps();
            $table->index(['user_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_contracts');
    }
};
