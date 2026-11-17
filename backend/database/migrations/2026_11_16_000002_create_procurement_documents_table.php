<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('document_number', 80);
            $table->date('document_date')->nullable();
            $table->string('status', 30)->default('draft');
            $table->json('payload');
            $table->timestamps();
            $table->index(['user_id', 'type', 'updated_at']);
            $table->unique(['user_id', 'type', 'document_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_documents');
    }
};
