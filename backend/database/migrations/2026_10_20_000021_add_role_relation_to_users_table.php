<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
        });

        $roleMap = [
            'admin' => 'admin', 'master_admin' => 'admin',
            'event_admin' => 'maker', 'manager' => 'approver',
            'officer_3' => 'checker', 'officer_2' => 'reviewer',
            'officer_1' => 'verifier', 'officer_0' => 'maker',
        ];
        $roleIds = DB::table('roles')->pluck('id', 'key');
        foreach ($roleMap as $legacyKey => $roleKey) {
            DB::table('users')->where('role', $legacyKey)->update(['role_id' => $roleIds[$roleKey]]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
