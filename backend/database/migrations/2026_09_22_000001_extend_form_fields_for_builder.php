<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('form_fields', function (Blueprint $table): void {
            $table->string('description', 300)->nullable()->after('label');
            $table->string('placeholder', 150)->nullable()->after('description');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->json('form_config')->nullable()->after('enabled_fields');
        });
    }

    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table): void {
            $table->dropColumn(['description', 'placeholder']);
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('form_config');
        });
    }
};
