<?php

namespace Tests\Feature;

use App\Models\StaffContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    private function contract(User $user, array $attributes = []): StaffContract
    {
        return StaffContract::create(['user_id' => $user->id, 'contract_type' => 'full_time', 'position' => 'Program Officer', 'department' => 'Program', 'start_date' => now()->subMonths(2)->toDateString(), 'end_date' => now()->addMonths(10)->toDateString(), 'salary' => 700, 'currency' => 'USD', ...$attributes]);
    }

    private function payload(User $user, array $overrides = []): array
    {
        return ['user_id' => $user->id, 'contract_type' => 'full_time', 'position' => 'Program Officer', 'department' => 'Program', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'salary' => '750', 'currency' => 'USD', ...$overrides];
    }

    public function test_staff_see_only_their_own_contracts_with_live_statuses(): void
    {
        $officer = User::factory()->create(['role' => 'officer_1']);
        $this->contract($officer, ['contract_type' => 'probationary', 'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->subMonths(3)->toDateString()]);
        $this->contract($officer);
        $this->contract($officer, ['start_date' => now()->addYear()->toDateString(), 'end_date' => null]);
        $this->contract(User::factory()->create(['role' => 'officer_1']), ['position' => 'Someone else']);
        Sanctum::actingAs($officer);

        $data = $this->getJson('/api/me/contracts')->assertOk()->json('data');

        $this->assertSame(['upcoming', 'active', 'ended'], array_column($data, 'status'));
        $this->assertNotContains('Someone else', array_column($data, 'position'));
    }

    public function test_attendees_and_guests_cannot_reach_contracts(): void
    {
        $this->getJson('/api/me/contracts')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'attendee']));
        $this->getJson('/api/me/contracts')->assertForbidden();
    }

    public function test_only_master_admins_can_manage_contracts(): void
    {
        $officer = User::factory()->create(['role' => 'officer_1']);
        $contract = $this->contract($officer);
        Sanctum::actingAs($officer);

        $this->postJson('/api/admin/contracts', $this->payload($officer))->assertForbidden();
        $this->postJson("/api/admin/contracts/{$contract->id}", $this->payload($officer, ['salary' => '9999']))->assertForbidden();
        $this->deleteJson("/api/admin/contracts/{$contract->id}")->assertForbidden();
        $this->getJson("/api/admin/contracts?user_id={$officer->id}")->assertForbidden();
        $this->assertSame('700.00', $contract->fresh()->salary);
    }

    public function test_master_admin_adds_a_contract_with_a_signed_pdf_and_the_owner_can_download_it(): void
    {
        Storage::fake('local');
        $officer = User::factory()->create(['role' => 'officer_1']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $id = $this->post('/api/admin/contracts', $this->payload($officer, ['file' => UploadedFile::fake()->create('signed.pdf', 200, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.has_file', true)->assertJsonPath('data.status', 'upcoming')->json('data.id');
        $path = StaffContract::find($id)->file_path;
        Storage::disk('local')->assertExists($path);

        Sanctum::actingAs($officer);
        $this->get("/api/contracts/{$id}/file")->assertOk()->assertDownload('signed.pdf');

        Sanctum::actingAs(User::factory()->create(['role' => 'officer_1']));
        $this->getJson("/api/contracts/{$id}/file")->assertForbidden();
    }

    public function test_contract_input_is_validated(): void
    {
        $officer = User::factory()->create(['role' => 'officer_1']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/admin/contracts', $this->payload($officer, ['contract_type' => 'forever', 'end_date' => '2026-01-01', 'salary' => '-5', 'currency' => 'EUR']))
            ->assertStatus(422)->assertJsonValidationErrors(['contract_type', 'end_date', 'salary', 'currency']);
        $this->post('/api/admin/contracts', $this->payload($officer, ['file' => UploadedFile::fake()->create('notes.docx', 20)]), ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/api/admin/contracts', $this->payload(User::factory()->create(['role' => 'attendee'])))->assertStatus(422)->assertJsonValidationErrors('user_id');
    }

    public function test_overlapping_contracts_are_refused(): void
    {
        $officer = User::factory()->create(['role' => 'officer_1']);
        $existing = $this->contract($officer, ['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/admin/contracts', $this->payload($officer, ['start_date' => '2027-06-01', 'end_date' => null]))->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->postJson('/api/admin/contracts', $this->payload($officer, ['start_date' => '2028-01-01', 'end_date' => '2028-12-31']))->assertCreated();
        // Editing a contract doesn't clash with itself.
        $this->postJson("/api/admin/contracts/{$existing->id}", $this->payload($officer, ['salary' => '800']))->assertOk()->assertJsonPath('data.salary', '800.00');
    }

    public function test_deleting_a_contract_removes_its_file(): void
    {
        Storage::fake('local');
        $officer = User::factory()->create(['role' => 'officer_1']);
        Storage::disk('local')->put('contracts/signed.pdf', '%PDF');
        $contract = $this->contract($officer, ['file_path' => 'contracts/signed.pdf', 'file_name' => 'signed.pdf']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->deleteJson("/api/admin/contracts/{$contract->id}")->assertOk();

        $this->assertModelMissing($contract);
        Storage::disk('local')->assertMissing('contracts/signed.pdf');
    }

    public function test_contracts_ending_within_30_days_are_flagged(): void
    {
        $officer = User::factory()->create(['role' => 'officer_1']);
        $this->contract($officer, ['end_date' => now()->addDays(10)->toDateString()]);
        Sanctum::actingAs($officer);

        $this->getJson('/api/me/contracts')->assertJsonPath('data.0.ending_soon', true);
    }
}
