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
            $table->string('account_status', 20)->default('active')->after('role')->index();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 120);
            $table->string('group', 60);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
            $table->string('staff_id', 40)->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('position', 120)->nullable();
            $table->string('employment_status', 20)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('workflow_definitions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('approval_rule', 20)->default('sequential');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workflow_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('stage_order');
            $table->string('name', 120);
            $table->foreignId('required_role_id')->constrained('roles')->restrictOnDelete();
            $table->unsignedSmallInteger('required_approvals')->default(1);
            $table->string('approval_rule', 20)->default('any');
            $table->timestamps();
            $table->unique(['workflow_definition_id', 'stage_order']);
        });

        $now = now();
        $roleRows = [
            ['key' => 'maker', 'name' => 'Maker', 'description' => 'Creates and prepares submissions.'],
            ['key' => 'checker', 'name' => 'Checker', 'description' => 'Checks submissions for completeness and accuracy.'],
            ['key' => 'reviewer', 'name' => 'Reviewer', 'description' => 'Reviews content and supporting information.'],
            ['key' => 'verifier', 'name' => 'Verifier', 'description' => 'Verifies required details and evidence.'],
            ['key' => 'approver', 'name' => 'Approver', 'description' => 'Makes the final approval decision.'],
            ['key' => 'admin', 'name' => 'Master Admin', 'description' => 'Manages users, roles, workflows, and system settings.'],
        ];
        foreach ($roleRows as &$role) {
            $role['is_system'] = true;
            $role['created_at'] = $now;
            $role['updated_at'] = $now;
        }
        unset($role);
        DB::table('roles')->insert($roleRows);

        $permissionRows = [
            ['key' => 'users.create', 'name' => 'Create users', 'group' => 'Users'],
            ['key' => 'users.manage', 'name' => 'Manage users', 'group' => 'Users'],
            ['key' => 'roles.manage', 'name' => 'Manage roles and permissions', 'group' => 'Access control'],
            ['key' => 'workflows.manage', 'name' => 'Manage workflows', 'group' => 'Workflows'],
            ['key' => 'events.view', 'name' => 'View events', 'group' => 'Events'],
            ['key' => 'events.manage', 'name' => 'Create and manage events', 'group' => 'Events'],
            ['key' => 'forms.create', 'name' => 'Create and edit forms', 'group' => 'Forms'],
            ['key' => 'forms.review', 'name' => 'Review submissions', 'group' => 'Forms'],
            ['key' => 'forms.verify', 'name' => 'Verify submissions', 'group' => 'Forms'],
            ['key' => 'forms.approve', 'name' => 'Approve submissions', 'group' => 'Forms'],
            ['key' => 'reports.view', 'name' => 'View reports', 'group' => 'Reports'],
            ['key' => 'checkins.manage', 'name' => 'Manage check-ins', 'group' => 'Events'],
        ];
        foreach ($permissionRows as &$permission) {
            $permission['description'] = null;
            $permission['created_at'] = $now;
            $permission['updated_at'] = $now;
        }
        unset($permission);
        DB::table('permissions')->insert($permissionRows);

        $roleIds = DB::table('roles')->pluck('id', 'key');
        $permissionIds = DB::table('permissions')->pluck('id', 'key');
        $defaults = [
            'maker' => ['events.view', 'events.manage', 'forms.create'],
            'checker' => ['events.view', 'forms.review'],
            'reviewer' => ['events.view', 'forms.review'],
            'verifier' => ['events.view', 'forms.verify'],
            'approver' => ['events.view', 'forms.approve', 'reports.view'],
            'admin' => array_keys($permissionIds->all()),
        ];
        $rolePermissions = [];
        foreach ($defaults as $roleKey => $keys) {
            foreach ($keys as $permissionKey) {
                $rolePermissions[] = ['role_id' => $roleIds[$roleKey], 'permission_id' => $permissionIds[$permissionKey]];
            }
        }
        DB::table('permission_role')->insert($rolePermissions);

        DB::table('workflow_definitions')->insert([
            'name' => 'Standard approval',
            'description' => 'A five step review from preparation through final approval.',
            'is_active' => true,
            'approval_rule' => 'sequential',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $workflowId = DB::table('workflow_definitions')->where('name', 'Standard approval')->value('id');
        $stages = [
            ['Maker', 'maker'], ['Checker', 'checker'], ['Reviewer', 'reviewer'], ['Verifier', 'verifier'], ['Approver', 'approver'],
        ];
        $stageRows = [];
        foreach ($stages as $index => [$name, $roleKey]) {
            $stageRows[] = [
                'workflow_definition_id' => $workflowId,
                'stage_order' => $index + 1,
                'name' => $name,
                'required_role_id' => $roleIds[$roleKey],
                'required_approvals' => 1,
                'approval_rule' => 'any',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('workflow_stages')->insert($stageRows);
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_stages');
        Schema::dropIfExists('workflow_definitions');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_status']);
            $table->dropColumn('account_status');
        });
    }
};
