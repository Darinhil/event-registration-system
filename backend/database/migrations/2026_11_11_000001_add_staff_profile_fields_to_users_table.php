<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('staff_id')->nullable()->unique();
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('staff_role_title')->nullable();
            $table->string('staff_team', 100)->nullable();
            $table->string('staff_position', 80)->nullable();
            $table->json('staff_authorities')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['staff_id']);
            $table->dropColumn(['staff_id', 'first_name', 'last_name', 'staff_role_title', 'staff_team', 'staff_position', 'staff_authorities']);
        });
    }
};
