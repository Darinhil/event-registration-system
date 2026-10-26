<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drafts can be saved before the schedule, venue and capacity are known.
     * Publishing still requires all of them (enforced in EventController).
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dateTime('starts_at')->nullable()->change();
            $table->dateTime('ends_at')->nullable()->change();
            $table->string('location')->nullable()->change();
            $table->unsignedInteger('capacity')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dateTime('starts_at')->nullable(false)->change();
            $table->dateTime('ends_at')->nullable(false)->change();
            $table->string('location')->nullable(false)->change();
            $table->unsignedInteger('capacity')->nullable(false)->change();
        });
    }
};
