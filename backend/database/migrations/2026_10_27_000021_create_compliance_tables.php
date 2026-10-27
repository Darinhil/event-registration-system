<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Policies every staff member must confirm. requirement_type decides how:
        // "acknowledgement" (read and confirm) or "declaration" (answer a question).
        Schema::create('compliance_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->string('category', 80);
            $table->text('description');
            $table->string('requirement_type', 30)->default('acknowledgement');
            $table->text('statement');
            $table->string('document_url', 500)->nullable();
            $table->unsignedSmallInteger('validity_months')->nullable(); // null = never needs renewing
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // One row per completion, never updated: renewing adds a new row, so HR keeps the full history.
        Schema::create('compliance_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('compliance_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at');
            $table->timestamp('expires_at')->nullable();
            $table->json('response')->nullable();
            $table->string('signature', 150);
            $table->timestamps();
            $table->index(['user_id', 'compliance_policy_id', 'completed_at']);
        });

        // The policies the portal already listed, so existing installs keep the same requirements.
        $now = now();
        $policies = [
            ['Adult Safeguarding (PSEAH)', 'Safeguarding', 'Protection from sexual exploitation, abuse and harassment of adults we work with.', 'acknowledgement', 'I confirm that I have read and understood the Adult Safeguarding (PSEAH) policy.'],
            ['Anti-Fraud', 'Financial & Integrity', 'How to prevent, recognise and report fraud and misuse of funds.', 'acknowledgement', 'I confirm that I have read and understood the Anti-Fraud policy.'],
            ['Child Safeguarding', 'Safeguarding', 'Keeping children safe in all programmes, activities and communications.', 'acknowledgement', 'I confirm that I have read and understood the Child Safeguarding policy.'],
            ['Code of Conduct', 'Ethics & Conduct', 'The standards of behaviour expected from every member of staff.', 'acknowledgement', 'I confirm that I have read and understood the Code of Conduct policy.'],
            ['Conflict of Interest', 'Ethics & Conduct', 'Declare any personal, financial or family interest that could affect your work decisions.', 'declaration', 'I declare that I have no undisclosed conflict of interest.'],
        ];
        DB::table('compliance_policies')->insert(array_map(fn (array $policy, int $index) => [
            'title' => $policy[0], 'category' => $policy[1], 'description' => $policy[2], 'requirement_type' => $policy[3], 'statement' => $policy[4],
            'validity_months' => 12, 'is_active' => true, 'sort_order' => $index, 'created_at' => $now, 'updated_at' => $now,
        ], $policies, array_keys($policies)));
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_records');
        Schema::dropIfExists('compliance_policies');
    }
};
