<?php

namespace Tests\Feature;

use App\Models\FinanceVoucher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceVoucherWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function voucherPayload(array $overrides = []): array
    {
        return array_replace([
            'number' => 'GV-TEST-001',
            'date' => '2026-11-16',
            'name' => 'Voucher owner',
            'purpose' => 'Office supplies',
            'lines' => [['description' => 'Paper', 'amount' => 25]],
            // Status and audit details from the client must never override the server.
            'workflowStatus' => 'approved',
            'financeWorkflow' => ['events' => [['actor_id' => 999, 'status' => 'approved']]],
        ], $overrides);
    }

    private function createVoucher(User $owner): int
    {
        Sanctum::actingAs($owner);

        return $this->postJson('/api/admin/finance-vouchers', $this->voucherPayload())
            ->assertCreated()
            ->assertJsonPath('data.payload.workflowStatus', 'draft')
            ->assertJsonPath('data.payload.financeWorkflow.events', [])
            ->assertJsonPath('data.can_edit', true)
            ->json('data.id');
    }

    public function test_voucher_moves_through_role_based_workflow_and_records_each_actor(): void
    {
        $owner = User::factory()->create(['role' => 'officer_1']);
        $manager = User::factory()->create(['role' => 'manager']);
        $eventAdmin = User::factory()->create(['role' => 'event_admin']);
        $admin = User::factory()->create(['role' => 'admin']);
        $voucherId = $this->createVoucher($owner);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'prepare'])
            ->assertOk()->assertJsonPath('data.payload.workflowStatus', 'prepared');
        $this->getJson('/api/admin/finance-vouchers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_act', false);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'check'])->assertForbidden();

        Sanctum::actingAs($manager);
        $this->getJson('/api/admin/finance-vouchers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_act', true);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'check'])
            ->assertOk()->assertJsonPath('data.payload.workflowStatus', 'checked')
            ->assertJsonPath('data.payload.financeWorkflow.events.1.actor_id', $manager->id);
        Sanctum::actingAs($owner);
        $this->putJson("/api/admin/finance-vouchers/{$voucherId}", $this->voucherPayload())->assertUnprocessable();

        Sanctum::actingAs($eventAdmin);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'verify'])
            ->assertOk()->assertJsonPath('data.payload.workflowStatus', 'verified')
            ->assertJsonPath('data.payload.financeWorkflow.events.2.actor_id', $eventAdmin->id);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'approve'])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('data.payload.workflowStatus', 'approved')
            ->assertJsonPath('data.payload.financeWorkflow.events.3.actor_id', $admin->id)
            ->assertJsonPath('data.can_edit', false);
    }

    public function test_role_and_stage_are_enforced_and_each_user_can_act_only_once(): void
    {
        $owner = User::factory()->create(['role' => 'officer_2']);
        $manager = User::factory()->create(['role' => 'manager']);
        $otherManager = User::factory()->create(['role' => 'manager']);
        $eventAdmin = User::factory()->create(['role' => 'event_admin']);
        $voucherId = $this->createVoucher($owner);

        Sanctum::actingAs($manager);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'verify'])->assertUnprocessable();
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'check'])->assertUnprocessable();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'prepare'])->assertOk();
        Sanctum::actingAs($manager);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'check'])->assertOk();
        Sanctum::actingAs($manager);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'verify'])->assertForbidden();
        Sanctum::actingAs($otherManager);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'verify'])->assertForbidden();
        Sanctum::actingAs($eventAdmin);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'verify'])->assertOk();
    }

    public function test_reviewers_only_see_their_actionable_queue_and_cannot_open_another_users_draft(): void
    {
        $owner = User::factory()->create(['role' => 'officer_0']);
        $otherOfficer = User::factory()->create(['role' => 'officer_3']);
        $manager = User::factory()->create(['role' => 'manager']);
        $voucherId = $this->createVoucher($owner);

        Sanctum::actingAs($otherOfficer);
        $this->getJson('/api/admin/finance-vouchers')->assertJsonCount(0, 'data');
        $this->getJson("/api/admin/finance-vouchers/{$voucherId}")->assertForbidden();

        Sanctum::actingAs($manager);
        $this->getJson('/api/admin/finance-vouchers')->assertJsonCount(0, 'data');
    }

    public function test_event_admin_cannot_check_and_verify_the_same_voucher(): void
    {
        $owner = User::factory()->create(['role' => 'officer_1']);
        $checker = User::factory()->create(['role' => 'event_admin']);
        $verifier = User::factory()->create(['role' => 'event_admin']);
        $voucherId = $this->createVoucher($owner);

        Sanctum::actingAs($owner);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'prepare'])->assertOk();
        Sanctum::actingAs($checker);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'check'])->assertOk();
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'verify'])->assertForbidden();
        Sanctum::actingAs($verifier);
        $this->patchJson("/api/admin/finance-vouchers/{$voucherId}/workflow", ['action' => 'verify'])
            ->assertOk()->assertJsonPath('data.payload.workflowStatus', 'verified');
    }
}
