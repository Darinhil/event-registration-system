<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('form_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('label', 150);
            $table->string('type', 40)->default('text');
            $table->boolean('required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['event_id', 'sort_order']);
        });
    }

    public function down(): void { Schema::dropIfExists('form_fields'); }
};
