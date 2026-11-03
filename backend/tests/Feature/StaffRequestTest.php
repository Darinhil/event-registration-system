<?php

namespace Tests\Feature;

use App\Models\PublicHoliday;
use App\Models\StaffRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officer = User::factory()->create(['role' => 'officer_1']);
        $this->manager = User::factory()->create(['role' => 'manager']);
    }

    private function leave(array $overrides = []): array
    {
        return ['type' => 'leave', 'approver_id' => $this->manager->id, 'details' => ['leave_type' => 'annual', 'start_date' => '2030-01-04', 'end_date' => '2030-01-08', 'reason' => 'Family'], ...$overrides];
    }

    public function test_only_staff_can_use_requests(): void
    {
        $this->getJson('/api/me/requests')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'attendee']));
        $this->postJson('/api/me/requests', $this->leave())->assertForbidden();
    }

    public function test_leave_is_saved_and_counts_only_working_days(): void
    {
        PublicHoliday::create(['date' => '2030-01-07', 'name' => 'Holiday']);
        Sanctum::actingAs($this->officer);

        // Fri 4 Jan to Tue 8 Jan 2030, minus the weekend and the holiday.
        $this->postJson('/api/me/requests', $this->leave())->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.details.working_days', 2)->assertJsonPath('data.approver', $this->manager->name);

        $this->getJson('/api/me/requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'leave')->assertJsonPath('data.0.can_cancel', true);
    }

    public function test_leave_on_a_weekend_only_is_rejected(): void
    {
        Sanctum::actingAs($this->officer);

        $this->postJson('/api/me/requests', $this->leave(['details' => ['leave_type' => 'annual', 'start_date' => '2030-01-05', 'end_date' => '2030-01-06']]))->assertStatus(422)->assertJsonValidationErrors('details.end_date');
    }

    public function test_each_request_type_is_validated_and_saved(): void
    {
        Sanctum::actingAs($this->officer);
        $base = ['approver_id' => $this->manager->id];

        $this->postJson('/api/me/requests', [...$base, 'type' => 'travel', 'details' => ['destination' => 'Siem Reap']])->assertStatus(422)->assertJsonValidationErrors(['details.purpose', 'details.start_date', 'details.transport']);
        $this->postJson('/api/me/requests', [...$base, 'type' => 'travel', 'details' => ['purpose' => 'Workshop', 'destination' => 'Siem Reap', 'start_date' => '2030-02-01', 'end_date' => '2030-02-03', 'transport' => 'Bus', 'costs' => ['transport' => 20, 'meals' => 15.5]]])->assertCreated()->assertJsonPath('data.details.costs.meals', 15.5);

        $this->postJson('/api/me/requests', [...$base, 'type' => 'fuel', 'details' => ['vehicle' => 'Car-001', 'month' => 2, 'year' => 2030, 'records' => []]])->assertStatus(422)->assertJsonValidationErrors('details.records');
        $this->postJson('/api/me/requests', [...$base, 'type' => 'fuel', 'details' => ['vehicle' => 'Car-001', 'month' => 2, 'year' => 2030, 'records' => [['date' => '2030-02-02', 'mileage' => 1000, 'liters' => 30, 'cost' => 36.5]]]])->assertCreated()->assertJsonPath('data.summary', 'Car-001 · Feb 2030 · $36.50');

        $this->postJson('/api/me/requests', [...$base, 'type' => 'purchase', 'details' => ['purpose' => 'Supplies', 'items' => [['description' => '', 'quantity' => 0, 'unit_cost' => 1]]]])->assertStatus(422)->assertJsonValidationErrors(['details.items.0.description', 'details.items.0.quantity']);
        $this->postJson('/api/me/requests', [...$base, 'type' => 'purchase', 'details' => ['purpose' => 'Supplies', 'items' => [['description' => 'Paper', 'quantity' => 2, 'unit_cost' => 4.5]]]])->assertCreated()->assertJsonPath('data.summary', 'Supplies · $9.00');
    }

    public function test_only_validated_details_are_stored_and_working_days_are_server_computed(): void
    {
        Sanctum::actingAs($this->officer);

        $id = $this->postJson('/api/me/requests', $this->leave(['details' => ['leave_type' => 'sick', 'start_date' => '2030-01-04', 'end_date' => '2030-01-04', 'working_days' => 99, 'junk' => 'x']]))->assertCreated()->json('data.id');

        $details = StaffRequest::find($id)->details;
        $this->assertSame(1, $details['working_days']);
        $this->assertArrayNotHasKey('junk', $details);
    }

    public function test_the_approver_must_be_a_supervisor_and_not_yourself(): void
    {
        Sanctum::actingAs($this->manager);
        $this->postJson('/api/me/requests', $this->leave())->assertStatus(422)->assertJsonValidationErrors('approver_id');

        Sanctum::actingAs($this->officer);
        $this->postJson('/api/me/requests', $this->leave(['approver_id' => User::factory()->create(['role' => 'officer_3'])->id]))->assertStatus(422)->assertJsonValidationErrors('approver_id');
        $this->postJson('/api/me/requests', $this->leave(['approver_id' => null]))->assertStatus(422)->assertJsonValidationErrors('approver_id');
    }

    public function test_supervisor_approves_or_rejects_with_a_reason(): void
    {
        Sanctum::actingAs($this->officer);
        $id = $this->postJson('/api/me/requests', $this->leave())->json('data.id');
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'approved'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'approved'])->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/me/requests/approvals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_decide', true)->assertJsonPath('data.0.staff', $this->officer->name);
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'rejected'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'rejected', 'note' => 'Busy week'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reviewed_by', $this->manager->name);
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'approved'])->assertStatus(422);
    }

    public function test_a_pending_request_can_be_cancelled_only_by_its_owner(): void
    {
        Sanctum::actingAs($this->officer);
        $id = $this->postJson('/api/me/requests', $this->leave())->json('data.id');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/requests/{$id}/cancel")->assertForbidden();
        $this->getJson('/api/me/requests')->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->officer);
        $this->postJson("/api/requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/requests/{$id}/cancel")->assertStatus(422);
    }

    public function test_profile_changes_go_to_any_master_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->officer);
        $id = $this->postJson('/api/me/requests', ['type' => 'profile_change', 'details' => ['field' => 'First name', 'value' => 'Sokha', 'reason' => 'Spelling']])->assertCreated()->assertJsonPath('data.approver', 'HR')->json('data.id');

        Sanctum::actingAs($this->manager);
        $this->getJson('/api/me/requests/approvals')->assertJsonCount(0, 'data');

        Sanctum::actingAs($admin);
        $this->getJson('/api/me/requests/approvals')->assertJsonCount(1, 'data');
        $this->postJson("/api/requests/{$id}/decision", ['decision' => 'approved'])->assertOk();
    }

    public function test_attachments_are_private(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->officer);
        $id = $this->post('/api/me/requests', [...$this->leave(), 'attachment' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.attachment_name', 'note.pdf')->json('data.id');
        $this->get("/api/requests/{$id}/attachment")->assertOk();

        Sanctum::actingAs(User::factory()->create(['role' => 'officer_2']));
        $this->getJson("/api/requests/{$id}/attachment")->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->get("/api/requests/{$id}/attachment")->assertOk();
    }

    public function test_personnel_profile_saves_as_a_draft_and_submits_when_complete(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->officer);
        $this->getJson('/api/me/personnel-profile')->assertOk()->assertJsonPath('data.profile', null);

        $this->putJson('/api/me/personnel-profile', ['profile' => ['gender' => 'Female', 'junk' => 'x']])->assertOk()->assertJsonPath('data.profile.gender', 'Female')->assertJsonPath('data.submitted_at', null)->assertJsonMissingPath('data.profile.junk');

        $complete = ['gender' => 'Female', 'dob' => '1990-01-01', 'birthPlace' => 'Kampot', 'idNumber' => 'N1', 'phone' => '012345678', 'email' => 'a@example.com', 'education' => [['degree' => 'BA', 'institution' => 'RUPP', 'year' => 2012]], 'contacts' => [['name' => 'Dara', 'relationship' => 'Sister', 'phone' => '012345679']], 'declarations' => ['accurate' => true, 'updates' => true, 'consent' => true], 'signature' => 'Sokha'];

        $this->putJson('/api/me/personnel-profile', ['submit' => true, 'profile' => ['gender' => 'Female']])->assertStatus(422)->assertJsonValidationErrors(['profile.dob', 'profile.signature', 'profile.declarations.accurate']);
        $this->putJson('/api/me/personnel-profile', ['submit' => true, 'profile' => $complete])->assertStatus(422)->assertJsonValidationErrors('documents');

        $this->post('/api/me/personnel-profile/documents/id', ['file' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.documents.id.name', 'id.pdf')->assertJsonMissingPath('data.documents.id.path');
        $this->post('/api/me/personnel-profile/documents/cv', ['file' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->post('/api/me/personnel-profile/documents/nope', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertNotFound();

        $submittedAt = $this->putJson('/api/me/personnel-profile', ['submit' => true, 'profile' => $complete])->assertOk()->assertJsonPath('data.documents.cv.name', 'cv.pdf')->json('data.submitted_at');
        $this->assertNotNull($submittedAt);

        // Editing afterwards needs a new submission.
        $this->putJson('/api/me/personnel-profile', ['profile' => $complete])->assertOk()->assertJsonPath('data.submitted_at', null);
    }

    public function test_personnel_profiles_are_only_visible_to_the_owner_and_hr(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->officer);
        $this->post('/api/me/personnel-profile/documents/id', ['file' => UploadedFile::fake()->create('id.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->get("/api/personnel-profiles/{$this->officer->id}/documents/id")->assertOk();

        Sanctum::actingAs($this->manager);
        $this->getJson("/api/admin/personnel-profiles/{$this->officer->id}")->assertForbidden();
        $this->getJson("/api/personnel-profiles/{$this->officer->id}/documents/id")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson("/api/admin/personnel-profiles/{$this->officer->id}")->assertOk()->assertJsonPath('data.documents.id.name', 'id.pdf');
        $this->get("/api/personnel-profiles/{$this->officer->id}/documents/id")->assertOk();
    }

    public function test_the_me_response_never_contains_the_personnel_profile(): void
    {
        $this->officer->update(['personnel_profile' => ['idNumber' => 'SECRET']]);
        Sanctum::actingAs($this->officer);

        $this->assertStringNotContainsString('SECRET', $this->getJson('/api/me')->assertOk()->getContent());
    }
}
