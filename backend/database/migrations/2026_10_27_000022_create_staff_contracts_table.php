<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Employment contracts on a staff member's record. Status is not stored:
        // it is worked out from the dates so it can never go stale.
        Schema::create('staff_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('contract_type', 30);
            $table->string('position', 150);
            $table->string('department', 100)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable(); // null = open-ended
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('file_path')->nullable(); // private disk, served only through an authorised download
            $table->string('file_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'start_date']);
        });
    }

    public function down(): void { Schema::dropIfExists('staff_contracts'); }
};
