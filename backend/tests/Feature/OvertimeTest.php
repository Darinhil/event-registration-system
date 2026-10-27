<?php

namespace Tests\Feature;

use App\Models\OvertimeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OvertimeTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officer = User::factory()->create(['name' => 'Vanna Sok', 'role' => 'officer_1']);
        $this->manager = User::factory()->create(['name' => 'Dara Manager', 'role' => 'manager']);
    }

    private function payload(array $overrides = []): array
    {
        return ['work_date' => now()->subDay()->toDateString(), 'start_time' => '17:30', 'end_time' => '20:00', 'supervisor_id' => $this->manager->id, 'reason' => 'Finishing the donor report before the deadline.', ...$overrides];
    }

    public function test_only_staff_can_use_overtime(): void
    {
        $this->getJson('/api/me/overtime')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'attendee']));
        $this->getJson('/api/me/overtime')->assertForbidden();
        $this->postJson('/api/me/overtime', $this->payload())->assertForbidden();
    }

    public function test_supervisor_list_only_contains_approvers_and_never_yourself(): void
    {
        User::factory()->create(['role' => 'officer_3']);
        Sanctum::actingAs($this->manager);

        $names = array_column($this->getJson('/api/me/overtime/supervisors')->assertOk()->json('data'), 'name');

        $this->assertNotContains('Dara Manager', $names);
        $this->assertNotContains('Vanna Sok', $names);
    }

    public function test_staff_submit_overtime_and_hours_are_calculated_on_the_server(): void
    {
        Sanctum::actingAs($this->officer);

        $this->postJson('/api/me/overtime', $this->payload(['minutes' => 999]))->assertCreated()
            ->assertJsonPath('data.minutes', 150)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.supervisor', 'Dara Manager');
        $this->getJson('/api/me/overtime')->assertJsonPath('summary.pending', 1)->assertJsonPath('data.0.can_cancel', true);
    }

    public function test_invalid_requests_are_rejected_with_clear_errors(): void
    {
        Sanctum::actingAs($this->officer);

        $this->postJson('/api/me/overtime', [])->assertStatus(422)->assertJsonValidationErrors(['work_date', 'start_time', 'end_time', 'supervisor_id', 'reason']);
        $this->postJson('/api/me/overtime', $this->payload(['start_time' => '20:00', 'end_time' => '18:00']))->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->postJson('/api/me/overtime', $this->payload(['start_time' => '06:00', 'end_time' => '23:00']))->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->postJson('/api/me/overtime', $this->payload(['end_time' => '17:40']))->assertStatus(422)->assertJsonValidationErrors('end_time');
        $this->postJson('/api/me/overtime', $this->payload(['work_date' => now()->subDays(90)->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('work_date');
        $this->postJson('/api/me/overtime', $this->payload(['supervisor_id' => User::factory()->create(['role' => 'officer_2'])->id]))->assertStatus(422)->assertJsonValidationErrors('supervisor_id');
        $this->postJson('/api/me/overtime', $this->payload(['reason' => 'Work']))->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertDatabaseCount('overtime_requests', 0);
    }

    public function test_overnight_work_needs_the_flag_and_spans_midnight(): void
    {
        Sanctum::actingAs($this->officer);

        $this->postJson('/api/me/overtime', $this->payload(['start_time' => '22:00', 'end_time' => '02:00', 'overnight' => true]))->assertCreated()
            ->assertJsonPath('data.minutes', 240)->assertJsonPath('data.overnight', true);
    }

    public function test_overlapping_requests_are_refused_but_rejected_ones_free_the_time(): void
    {
        Sanctum::actingAs($this->officer);
        $first = $this->postJson('/api/me/overtime', $this->payload())->assertCreated()->json('data.id');

        $this->postJson('/api/me/overtime', $this->payload(['start_time' => '19:00', 'end_time' => '21:00']))->assertStatus(422)->assertJsonValidationErrors('start_time');
        $this->postJson('/api/me/overtime', $this->payload(['start_time' => '20:00', 'end_time' => '21:00']))->assertCreated(); // touching, not overlapping

        $this->postJson("/api/overtime/{$first}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson('/api/me/overtime', $this->payload(['start_time' => '18:00', 'end_time' => '19:00']))->assertCreated();
    }

    public function test_nobody_can_supervise_themselves(): void
    {
        $otherManager = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($this->manager);

        $this->postJson('/api/me/overtime', $this->payload(['supervisor_id' => $this->manager->id]))->assertStatus(422)->assertJsonValidationErrors('supervisor_id');
        $this->postJson('/api/me/overtime', $this->payload(['supervisor_id' => $otherManager->id]))->assertCreated();
    }

    public function test_supervisor_approves_and_the_staff_member_sees_the_outcome(): void
    {
        Sanctum::actingAs($this->officer);
        $id = $this->postJson('/api/me/overtime', $this->payload(['work_date' => now()->toDateString()]))->json('data.id');

        Sanctum::actingAs($this->manager);
        $approvals = $this->getJson('/api/me/overtime/approvals')->assertOk()->json('data');
        $this->assertSame('Vanna Sok', $approvals[0]['staff']);
        $this->assertTrue($approvals[0]['can_decide']);
        $this->postJson("/api/overtime/{$id}/decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.reviewed_by', 'Dara Manager');
        $this->postJson("/api/overtime/{$id}/decision", ['decision' => 'rejected', 'note' => 'Changed my mind'])->assertStatus(422);

        Sanctum::actingAs($this->officer);
        $this->getJson('/api/me/overtime')->assertJsonPath('data.0.status', 'approved')->assertJsonPath('summary.approved_this_month_minutes', 150);
        $this->postJson("/api/overtime/{$id}/cancel")->assertStatus(422);
    }

    public function test_rejection_needs_a_reason(): void
    {
        Sanctum::actingAs($this->officer);
        $id = $this->postJson('/api/me/overtime', $this->payload())->json('data.id');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/overtime/{$id}/decision", ['decision' => 'rejected'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/overtime/{$id}/decision", ['decision' => 'rejected', 'note' => 'Not pre-agreed.'])->assertOk()->assertJsonPath('data.review_note', 'Not pre-agreed.');
    }

    public function test_only_the_chosen_supervisor_or_a_master_admin_can_decide(): void
    {
        Sanctum::actingAs($this->officer);
        $id = $this->postJson('/api/me/overtime', $this->payload())->json('data.id');

        $this->postJson("/api/overtime/{$id}/decision", ['decision' => 'approved'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson("/api/overtime/{$id}/decision", ['decision' => 'approved'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));
        $this->getJson('/api/me/overtime/approvals')->assertForbidden();
        $this->postJson("/api/overtime/{$id}/cancel")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson("/api/overtime/{$id}/decision", ['decision' => 'approved'])->assertOk();
        $this->assertSame('approved', OvertimeRequest::find($id)->status);
    }
}
