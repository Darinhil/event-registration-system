<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void { Schema::create('registrations', function (Blueprint $table): void { $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('event_name'); $table->dateTime('event_date'); $table->uuid('qr_token')->unique(); $table->string('status')->default('confirmed'); $table->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('registrations'); }
};