<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->index(['status', 'starts_at']);
            $table->index('created_by');
        });
        Schema::table('registrations', function (Blueprint $table): void {
            $table->index(['event_id', 'created_at']);
            $table->index(['event_id', 'status']);
        });
        Schema::table('check_ins', function (Blueprint $table): void {
            $table->index('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex(['events_status_starts_at_index']);
            $table->dropIndex(['events_created_by_index']);
        });
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropIndex(['registrations_event_id_created_at_index']);
            $table->dropIndex(['registrations_event_id_status_index']);
        });
        Schema::table('check_ins', function (Blueprint $table): void {
            $table->dropIndex(['check_ins_checked_in_at_index']);
        });
    }
};
