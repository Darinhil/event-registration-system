<?php

namespace Tests\Feature;

use App\Models\CompliancePolicy;
use App\Models\ComplianceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $name = 'Sokha Leng'): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'officer_1']);
        Sanctum::actingAs($user);
        return $user;
    }

    private function policy(string $type = 'acknowledgement'): CompliancePolicy
    {
        return CompliancePolicy::where('requirement_type', $type)->orderBy('sort_order')->firstOrFail();
    }

    public function test_only_signed_in_staff_can_use_compliances(): void
    {
        $this->getJson('/api/me/compliances')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'attendee']));
        $this->getJson('/api/me/compliances')->assertForbidden();
        $this->postJson("/api/me/compliances/{$this->policy()->id}/complete", ['acknowledged' => true, 'signature' => 'x'])->assertForbidden();
    }

    public function test_new_staff_member_sees_every_active_policy_as_pending(): void
    {
        $this->staff();
        CompliancePolicy::create(['title' => 'Retired policy', 'category' => 'Old', 'description' => '-', 'statement' => '-', 'is_active' => false]);

        $response = $this->getJson('/api/me/compliances')->assertOk();

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(['required' => 5, 'completed' => 0, 'pending' => 5, 'expired' => 0, 'renew_soon' => 0, 'percent' => 0], $response->json('summary'));
        $this->assertSame('declaration', collect($response->json('data'))->firstWhere('title', 'Conflict of Interest')['requirement_type']);
    }

    public function test_acknowledgement_needs_the_box_ticked_and_the_users_own_name(): void
    {
        $this->staff('Sokha Leng');
        $url = "/api/me/compliances/{$this->policy()->id}/complete";

        $this->postJson($url, ['signature' => 'Sokha Leng'])->assertStatus(422)->assertJsonValidationErrors('acknowledged');
        $this->postJson($url, ['acknowledged' => true, 'signature' => 'Someone Else'])->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->assertDatabaseCount('compliance_records', 0);

        $this->postJson($url, ['acknowledged' => true, 'signature' => '  sokha   leng '])->assertCreated()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.can_complete', false);

        $record = ComplianceRecord::firstOrFail();
        $this->assertTrue($record->expires_at->isSameDay(now()->addMonths(12)));
    }

    public function test_completion_is_saved_and_reflected_after_a_reload(): void
    {
        $this->staff();
        $this->postJson("/api/me/compliances/{$this->policy()->id}/complete", ['acknowledged' => true, 'signature' => 'Sokha Leng'])->assertCreated();

        $summary = $this->getJson('/api/me/compliances')->json('summary');
        $this->assertSame([1, 4, 20], [$summary['completed'], $summary['pending'], $summary['percent']]);
    }

    public function test_a_completed_policy_cannot_be_completed_again_until_renewal_is_due(): void
    {
        $this->staff();
        $url = "/api/me/compliances/{$this->policy()->id}/complete";
        $this->postJson($url, ['acknowledged' => true, 'signature' => 'Sokha Leng'])->assertCreated();

        $this->postJson($url, ['acknowledged' => true, 'signature' => 'Sokha Leng'])->assertStatus(422)->assertJsonValidationErrors('policy');

        $this->travel(11)->months();
        $this->travel(15)->days();
        $item = collect($this->getJson('/api/me/compliances')->json('data'))->firstWhere('id', $this->policy()->id);
        $this->assertTrue($item['renew_soon']);
        $this->postJson($url, ['acknowledged' => true, 'signature' => 'Sokha Leng'])->assertCreated();
        $this->assertDatabaseCount('compliance_records', 2); // history is kept
    }

    public function test_completions_expire_after_the_validity_period(): void
    {
        $this->staff();
        $this->postJson("/api/me/compliances/{$this->policy()->id}/complete", ['acknowledged' => true, 'signature' => 'Sokha Leng'])->assertCreated();

        $this->travel(13)->months();
        $response = $this->getJson('/api/me/compliances');

        $this->assertSame('expired', collect($response->json('data'))->firstWhere('id', $this->policy()->id)['status']);
        $this->assertSame([0, 1, 0], [$response->json('summary.completed'), $response->json('summary.expired'), $response->json('summary.percent')]);
    }

    public function test_declaration_requires_an_answer_and_details_for_a_conflict(): void
    {
        $this->staff();
        $url = "/api/me/compliances/{$this->policy('declaration')->id}/complete";

        $this->postJson($url, ['signature' => 'Sokha Leng'])->assertStatus(422)->assertJsonValidationErrors('has_conflict');
        $this->postJson($url, ['has_conflict' => true, 'signature' => 'Sokha Leng'])->assertStatus(422)->assertJsonValidationErrors('details');

        $this->postJson($url, ['has_conflict' => true, 'details' => 'My brother supplies office equipment.', 'signature' => 'Sokha Leng'])->assertCreated()
            ->assertJsonPath('data.response', ['has_conflict' => true, 'details' => 'My brother supplies office equipment.']);
    }

    public function test_staff_only_see_their_own_records(): void
    {
        $other = User::factory()->create(['name' => 'Dara Chan', 'role' => 'officer_1']);
        ComplianceRecord::create(['compliance_policy_id' => $this->policy()->id, 'user_id' => $other->id, 'completed_at' => now(), 'expires_at' => now()->addYear(), 'signature' => 'Dara Chan']);

        $this->staff();

        $this->assertSame(0, $this->getJson('/api/me/compliances')->json('summary.completed'));
    }

    public function test_inactive_or_unknown_policies_cannot_be_completed(): void
    {
        $this->staff();
        $retired = CompliancePolicy::create(['title' => 'Retired', 'category' => 'Old', 'description' => '-', 'statement' => '-', 'is_active' => false]);

        $this->postJson("/api/me/compliances/{$retired->id}/complete", ['acknowledged' => true, 'signature' => 'Sokha Leng'])->assertNotFound();
        $this->postJson('/api/me/compliances/9999/complete', ['acknowledged' => true, 'signature' => 'Sokha Leng'])->assertNotFound();
    }
}
