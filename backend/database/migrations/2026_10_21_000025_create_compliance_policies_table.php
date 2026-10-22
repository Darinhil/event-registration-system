<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_policies', function (Blueprint $table) {
            $table->id();
            $table->string('key', 160)->unique();
            $table->string('title', 150);
            $table->string('category', 100);
            $table->text('description');
            $table->string('icon', 20)->default('shield');
            $table->timestamps();
        });

        $now = now();
        DB::table('compliance_policies')->insert([
            [
                'key' => 'adult_safeguarding_pseah',
                'title' => 'Adult Safeguarding (PSEAH)',
                'category' => 'Safeguarding',
                'description' => 'I confirm that I have read and understood the Adult Safeguarding (PSEAH) policy.',
                'icon' => 'shield',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'anti_fraud',
                'title' => 'Anti-Fraud',
                'category' => 'Financial & Integrity',
                'description' => 'I confirm that I have read and understood the Anti-Fraud policy.',
                'icon' => 'alert',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'child_safeguarding',
                'title' => 'Child Safeguarding',
                'category' => 'Safeguarding',
                'description' => 'I confirm that I have read and understood the Child Safeguarding policy.',
                'icon' => 'shield',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'staff_code_of_conduct',
                'title' => 'Staff Code of Conduct',
                'category' => 'Ethics & Conduct',
                'description' => 'I confirm that I have read and understood the Staff Code of Conduct.',
                'icon' => 'drop',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'conflict_of_interest_declaration',
                'title' => 'Conflict of Interest Declaration',
                'category' => 'Ethics & Conduct',
                'description' => 'I declare that I have disclosed any actual, potential, or perceived conflicts of interest.',
                'icon' => 'drop',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'data_protection',
                'title' => 'Data Protection and Confidentiality',
                'category' => 'Data & Privacy',
                'description' => 'I confirm that I will protect personal and confidential information in line with organizational policies.',
                'icon' => 'shield',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_policies');
    }
};
