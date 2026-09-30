<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->string('full_name')->nullable()->after('event_date');
            $table->string('gender')->nullable()->after('full_name');
            $table->string('age_group')->nullable()->after('gender');
            $table->string('disability_type')->nullable()->after('age_group');
            $table->text('address')->nullable()->after('disability_type');
            $table->string('business_entity')->nullable()->after('address');
            $table->string('position')->nullable()->after('business_entity');
            $table->string('telephone', 30)->nullable()->after('position');
            $table->boolean('photo_consent')->default(false)->after('telephone');
            $table->text('signature')->nullable()->after('photo_consent');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void { $table->dropColumn(['full_name', 'gender', 'age_group', 'disability_type', 'address', 'business_entity', 'position', 'telephone', 'photo_consent', 'signature']); });
    }
};