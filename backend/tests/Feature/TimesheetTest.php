<?php

namespace Tests\Feature;

use App\Models\LeaveEntitlement;
use App\Models\PublicHoliday;
use App\Models\StaffContract;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** October 2026: 1 Oct is a Thursday; weekends are 3–4, 10–11, 17–18, 24–25 and 31. */
class TimesheetTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/me/timesheets/2026/10';
    private User $officer;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officer = User::factory()->create(['name' => 'Vanna Sok', 'role' => 'officer_1']);
        $this->manager = User::factory()->create(['name' => 'Dara Manager', 'role' => 'manager']);
        PublicHoliday::create(['date' => '2026-10-15', 'name' => 'Test Holiday']);
    }

    /** Standard hours on every working day of October 2026, with optional overrides. */
    private function fullMonth(array $overrides = []): array
    {
        $entries = [];
        foreach (range(1, 31) as $day) {
            $weekday = (int) date('N', strtotime("2026-10-$day"));
            if ($weekday >= 6 || $day === 15) continue;
            $entries[(string) $day] = ['hours' => 6.5];
        }
        foreach ($overrides as $day => $entry) $entries[(string) $day] = $entry;
        return $entries;
    }

    private function submit(array $entries, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(self::URL.'/submit', ['entries' => $entries, 'approver_id' => $this->manager->id, 'certify' => true, ...$extra]);
    }

    public function test_only_staff_can_use_timesheets(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'attendee']));
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_a_new_month_is_an_unsaved_draft_with_real_employee_details_and_holidays(): void
    {
        StaffContract::create(['user_id' => $this->officer->id, 'contract_type' => 'full_time', 'position' => 'Program Officer', 'department' => 'Program', 'start_date' => now()->subYear()->toDateString(), 'end_date' => null, 'currency' => 'USD']);
        Sanctum::actingAs($this->officer);

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame(['draft', true, null], [$data['status'], $data['editable'], $data['id']]);
        $this->assertSame('ST-'.str_pad((string) $this->officer->id, 5, '0', STR_PAD_LEFT), $data['employee']['staff_id']);
        $this->assertSame(['Program Officer', 'Program'], [$data['employee']['position'], $data['employee']['department']]);
        $this->assertCount(31, $data['days']);
        $this->assertSame('Test Holiday', $data['days'][14]['holiday']);
        $this->assertFalse($data['days'][14]['working']);
        $this->assertTrue($data['days'][2]['weekend']);
        $this->assertSame(['Dara Manager'], array_column($data['approvers'], 'name'));
        $this->assertDatabaseCount('timesheets', 0);
    }

    public function test_drafts_save_and_reload(): void
    {
        Sanctum::actingAs($this->officer);
        $this->putJson(self::URL, ['entries' => ['1' => ['hours' => 6.5], '2' => ['hours' => 3, 'leave_type' => 'annual', 'leave_part' => 'pm', 'note' => 'Doctor']], 'approver_id' => $this->manager->id])->assertOk();

        $data = $this->getJson(self::URL)->json('data');
        $this->assertSame(6.5, (float) $data['entries']['1']['hours']);
        $this->assertSame('pm', $data['entries']['2']['leave_part']);
        $this->assertSame('Dara Manager', $data['approver']['name']);
        $this->assertSame(0.5, (float) collect($data['balances'])->firstWhere('type', 'annual')['used_this_month']);
    }

    public function test_entry_rules_are_enforced_on_the_server(): void
    {
        Sanctum::actingAs($this->officer);
        $bad = [
            ['1' => ['hours' => 25]],
            ['1' => ['hours' => -1]],
            ['1' => ['hours' => 6.1]],
            ['3' => ['hours' => 4]],                                            // weekend
            ['15' => ['hours' => 4]],                                           // public holiday
            ['1' => ['hours' => 2, 'leave_type' => 'annual', 'leave_part' => 'full']],
            ['1' => ['hours' => 4, 'leave_type' => 'annual', 'leave_part' => 'am']],
            ['1' => ['leave_type' => 'holiday']],
            ['32' => ['hours' => 6.5]],
        ];
        foreach ($bad as $entries) $this->putJson(self::URL, ['entries' => $entries])->assertStatus(422);
        $this->putJson(self::URL, ['entries' => ['1' => ['hours' => 3.25, 'leave_type' => 'sick', 'leave_part' => 'am']]])->assertOk();
    }

    public function test_submit_requires_every_working_day_an_approver_and_certification(): void
    {
        Sanctum::actingAs($this->officer);
        $entries = $this->fullMonth();
        unset($entries['7']);

        $this->submit($entries)->assertStatus(422)->assertJsonValidationErrors('entries')->assertJsonFragment(['Enter hours or leave for every working day. Missing: 7 Oct.']);
        $this->submit($this->fullMonth(), ['certify' => false])->assertStatus(422)->assertJsonValidationErrors('certify');
        $this->submit($this->fullMonth(), ['approver_id' => null])->assertStatus(422)->assertJsonValidationErrors('approver_id');
        $this->submit($this->fullMonth(), ['approver_id' => $this->officer->id])->assertStatus(422)->assertJsonValidationErrors('approver_id');
        $this->submit($this->fullMonth())->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.editable', false);
    }

    public function test_leave_cannot_exceed_the_balance(): void
    {
        LeaveEntitlement::create(['user_id' => $this->officer->id, 'year' => 2026, 'leave_type' => 'annual', 'entitled_days' => 1, 'carried_over_days' => 0.5]);
        Sanctum::actingAs($this->officer);
        $leave = ['leave_type' => 'annual', 'leave_part' => 'full'];

        $this->submit($this->fullMonth(['1' => $leave, '2' => $leave]))->assertStatus(422)->assertJsonValidationErrors('leave.annual');
        $this->submit($this->fullMonth(['1' => $leave, '2' => ['hours' => 3, 'leave_type' => 'annual', 'leave_part' => 'pm']]))->assertOk();
    }

    public function test_balances_count_other_months(): void
    {
        LeaveEntitlement::create(['user_id' => $this->officer->id, 'year' => 2026, 'leave_type' => 'annual', 'entitled_days' => 15, 'carried_over_days' => 2]);
        Timesheet::create(['user_id' => $this->officer->id, 'year' => 2026, 'month' => 8, 'status' => 'approved', 'entries' => ['3' => ['leave_type' => 'annual', 'leave_part' => 'full'], '4' => ['leave_type' => 'annual', 'leave_part' => 'full']]]);
        Timesheet::create(['user_id' => $this->officer->id, 'year' => 2026, 'month' => 9, 'status' => 'submitted', 'entries' => ['1' => ['leave_type' => 'annual', 'leave_part' => 'am']]]);
        Timesheet::create(['user_id' => $this->officer->id, 'year' => 2026, 'month' => 7, 'status' => 'draft', 'entries' => ['1' => ['leave_type' => 'annual', 'leave_part' => 'full']]]);
        Sanctum::actingAs($this->officer);

        $annual = collect($this->getJson(self::URL)->json('data.balances'))->firstWhere('type', 'annual');

        $this->assertEquals(['entitlement' => 15, 'carried_over' => 2, 'used_before' => 2, 'pending' => 0.5, 'used_this_month' => 0, 'remaining' => 14.5], collect($annual)->only(['entitlement', 'carried_over', 'used_before', 'pending', 'used_this_month', 'remaining'])->all());
    }

    public function test_submitted_timesheets_are_locked_until_recalled(): void
    {
        Sanctum::actingAs($this->officer);
        $this->submit($this->fullMonth())->assertOk();

        $this->putJson(self::URL, ['entries' => []])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->postJson(self::URL.'/recall')->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.editable', true);
        $this->assertSame(['submitted', 'recalled'], array_column(Timesheet::first()->history, 'action'));
    }

    public function test_approver_returns_with_comments_and_the_staff_member_resubmits(): void
    {
        Sanctum::actingAs($this->officer);
        $id = $this->submit($this->fullMonth())->json('data.id');

        Sanctum::actingAs($this->manager);
        $this->assertSame('Vanna Sok', $this->getJson('/api/me/timesheet-approvals')->assertOk()->json('data.0.staff'));
        $this->getJson("/api/timesheets/{$id}")->assertOk()->assertJsonPath('data.can_decide', true)->assertJsonPath('data.editable', false);
        $this->postJson("/api/timesheets/{$id}/decision", ['decision' => 'returned'])->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->postJson("/api/timesheets/{$id}/decision", ['decision' => 'returned', 'comment' => 'Please check week 2.', 'day_comments' => ['8' => 'You were at the workshop all day.']])->assertOk();

        Sanctum::actingAs($this->officer);
        $data = $this->getJson(self::URL)->json('data');
        $this->assertSame(['returned', true, 'Please check week 2.', 'You were at the workshop all day.'], [$data['status'], $data['editable'], $data['return_comment'], $data['day_comments']['8']]);
        $this->submit($this->fullMonth(['8' => ['hours' => 7.5]]))->assertOk()->assertJsonPath('data.status', 'submitted');

        Sanctum::actingAs($this->manager);
        $this->postJson("/api/timesheets/{$id}/decision", ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->assertSame([$id], array_column($this->getJson('/api/me/timesheet-approvals')->json('data'), 'id')); // HR queue
        $this->postJson("/api/timesheets/{$id}/process")->assertOk()->assertJsonPath('data.processed', true);
        $this->assertSame([], $this->getJson('/api/me/timesheet-approvals')->json('data'));
        $this->assertSame(['submitted', 'returned', 'submitted', 'approved', 'processed'], array_column(Timesheet::find($id)->history, 'action'));
    }

    public function test_only_the_assigned_approver_or_a_master_admin_can_see_or_decide(): void
    {
        Sanctum::actingAs($this->officer);
        $id = $this->submit($this->fullMonth())->json('data.id');

        $this->postJson("/api/timesheets/{$id}/decision", ['decision' => 'approved'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson("/api/timesheets/{$id}")->assertForbidden();
        $this->postJson("/api/timesheets/{$id}/decision", ['decision' => 'approved'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'officer_2']));
        $this->getJson('/api/me/timesheet-approvals')->assertForbidden();
        $this->postJson("/api/timesheets/{$id}/process")->assertForbidden();
    }

    public function test_attachments_are_private_and_only_your_own_can_be_attached(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->officer);
        $file = $this->post(self::URL.'/attachments', ['file' => UploadedFile::fake()->create('certificate.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->post(self::URL.'/attachments', ['file' => UploadedFile::fake()->create('notes.exe', 10)], ['Accept' => 'application/json'])->assertStatus(422);
        $id = $this->putJson(self::URL, ['entries' => ['5' => ['leave_type' => 'sick', 'leave_part' => 'full', 'attachment' => $file]]])->assertOk()->json('data.id');

        $this->get("/api/timesheets/{$id}/attachments/5")->assertOk()->assertDownload('certificate.pdf');

        $other = User::factory()->create(['role' => 'officer_1']);
        Sanctum::actingAs($other);
        $this->get("/api/timesheets/{$id}/attachments/5")->assertForbidden();
        $this->putJson(self::URL, ['entries' => ['5' => ['leave_type' => 'sick', 'leave_part' => 'full', 'attachment' => $file]]])->assertStatus(422);
    }
}
