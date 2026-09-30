<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->foreignId('event_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('registration_code')->nullable()->unique()->after('event_id');
            $table->unsignedTinyInteger('age')->nullable()->after('gender');
            $table->string('phone')->nullable()->after('age');
            $table->string('email')->nullable()->after('phone');
            $table->string('organization')->nullable()->after('email');
            $table->string('profile_photo')->nullable()->after('organization');
            $table->string('emergency_contact_name')->nullable()->after('profile_photo');
            $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void { $table->dropForeign(['event_id']); $table->dropColumn(['event_id', 'registration_code', 'age', 'phone', 'email', 'organization', 'profile_photo', 'emergency_contact_name', 'emergency_contact_phone']); });
    }
};