<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_admin_can_save_and_read_general_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->putJson('/api/admin/settings', [
            'site_name' => 'Community Events',
            'registration_notice' => 'Please arrive early.',
            'maintenance_mode' => true,
        ])->assertOk()->assertJsonPath('data.site_name', 'Community Events');

        $this->getJson('/api/public-settings')
            ->assertOk()
            ->assertJsonPath('data.site_name', 'Community Events')
            ->assertJsonPath('data.registration_notice', 'Please arrive early.')
            ->assertJsonPath('data.maintenance_mode', true);
    }

    public function test_regular_staff_cannot_change_general_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/admin/settings', ['site_name' => 'Not allowed'])
            ->assertForbidden();
    }

    public function test_user_manager_can_create_and_link_a_staff_record(): void
    {
        $this->grant('maker', ['users.manage']);
        $manager = User::factory()->create(['role' => 'maker']);
        $login = User::factory()->create(['role' => 'maker']);
        Sanctum::actingAs($manager);

        $this->postJson('/api/admin/settings/access-control/staff', [
            'staff_id' => 'STAFF-100',
            'user_id' => $login->id,
            'name' => 'Taylor Staff',
            'phone' => '012345678',
            'position' => 'Coordinator',
            'employment_status' => 'active',
        ])->assertCreated()->assertJsonPath('data.user.id', $login->id);
    }

    public function test_workflow_manager_can_save_stages_and_role_manager_cannot_grant_unheld_permissions(): void
    {
        $this->grant('maker', ['workflows.manage', 'roles.manage']);
        $manager = User::factory()->create(['role' => 'maker']);
        Sanctum::actingAs($manager);

        $this->postJson('/api/admin/settings/access-control/workflows', [
            'name' => 'Event approval',
            'description' => 'Review before publication.',
            'is_active' => true,
            'approval_rule' => 'sequential',
            'stages' => [[
                'name' => 'Final review',
                'required_role_id' => Role::query()->where('key', 'approver')->value('id'),
                'required_approvals' => 1,
                'approval_rule' => 'any',
            ]],
        ])->assertCreated()->assertJsonPath('data.stages.0.name', 'Final review');

        $approverPermission = Permission::query()->where('key', 'forms.approve')->value('id');
        $this->putJson('/api/admin/settings/access-control/roles/' . Role::query()->where('key', 'checker')->value('id') . '/permissions', [
            'permission_ids' => [$approverPermission],
        ])->assertForbidden();
    }

    public function test_non_admin_cannot_suspend_master_admin(): void
    {
        $this->grant('maker', ['users.manage']);
        $manager = User::factory()->create(['role' => 'maker']);
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($manager);

        $this->putJson('/api/admin/settings/access-control/users/' . $admin->id . '/status', [
            'account_status' => 'suspended',
        ])->assertForbidden();
    }

    private function grant(string $roleKey, array $permissionKeys): void
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $ids = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $role->permissions()->syncWithoutDetaching($ids);
    }
}
