<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\StaffAccountInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function master(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_master_admin_creates_account_without_exposing_password(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->master());

        $response = $this->postJson('/api/admin/admin-accounts', [
            'first_name' => 'Dara',
            'last_name' => 'Sok',
            'email' => 'dara@example.test',
            'phone' => '012345678',
            'staff_team' => 'Management',
            'staff_position' => 'Manager',
            'staff_role_title' => 'operations_lead',
            'staff_authorities' => ['approver'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Dara Sok')
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.staff_role_title', 'operations_lead')
            ->assertJsonPath('data.staff_team', 'Management')
            ->assertJsonPath('data.staff_position', 'Manager')
            ->assertJsonPath('data.staff_authorities.0', 'approver');

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertDatabaseHas('users', ['email' => 'dara@example.test', 'role' => 'manager', 'username' => null]);
        $this->assertStringStartsWith('STF-', $response->json('data.staff_id'));
        $this->assertNotSame('secret-pass', User::where('email', 'dara@example.test')->value('password'));
        Notification::assertSentTo(User::where('email', 'dara@example.test')->first(), StaffAccountInvite::class);
    }

    public function test_create_validates_email_and_staff_role(): void
    {
        Sanctum::actingAs($this->master());
        User::factory()->create(['email' => 'taken@example.test']);

        $this->postJson('/api/admin/admin-accounts', [
            'first_name' => 'X',
            'last_name' => 'Y',
            'email' => 'taken@example.test',
            'staff_team' => 'Management',
            'staff_position' => 'Manager',
            'staff_role_title' => 'invalid',
            'staff_authorities' => [],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'staff_role_title']);
    }

    public function test_create_rejects_unknown_staff_role_title(): void
    {
        Sanctum::actingAs($this->master());

        $this->postJson('/api/admin/admin-accounts', [
            'first_name' => 'X',
            'last_name' => 'Y',
            'email' => 'x@example.test',
            'staff_team' => 'Management',
            'staff_position' => 'Manager',
            'staff_role_title' => 'unknown',
            'staff_authorities' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['staff_role_title']);
    }

    public function test_only_master_admin_can_manage_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'event_admin']));

        $this->getJson('/api/admin/admin-accounts')->assertForbidden();
        $this->postJson('/api/admin/admin-accounts', ['first_name' => 'X', 'last_name' => 'Y'])->assertForbidden();
    }

    public function test_master_admin_changes_another_accounts_role(): void
    {
        Sanctum::actingAs($this->master());
        $member = User::factory()->create(['role' => 'event_admin']);

        $this->putJson("/api/admin/admin-accounts/{$member->id}", ['role' => 'officer_2'])->assertOk()->assertJsonPath('data.role', 'officer_2');
    }

    public function test_master_admin_can_assign_staff_role_title_without_changing_access_role(): void
    {
        Sanctum::actingAs($this->master());
        $member = User::factory()->create(['role' => 'manager']);

        $this->putJson("/api/admin/admin-accounts/{$member->id}", ['staff_role_title' => 'reviewer'])
            ->assertOk()
            ->assertJsonPath('data.role', 'officer_0')
            ->assertJsonPath('data.staff_role_title', 'reviewer');
    }

    public function test_invited_staff_can_set_password_once_using_email_token(): void
    {
        $user = User::factory()->create([
            'role' => 'officer_2',
            'staff_role_title' => 'maintenance_tech',
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/set-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'new-secure-password',
        ])->assertOk();

        $this->postJson('/api/auth/set-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'another-secure-password',
            'password_confirmation' => 'another-secure-password',
        ])->assertUnprocessable();
    }

    public function test_reviewer_role_cannot_create_or_check_in_but_can_read_reports(): void
    {
        $event = \App\Models\Event::create([
            'name' => 'Test event',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'location' => 'Phnom Penh',
            'capacity' => 10,
            'enabled_fields' => [],
            'status' => 'published',
        ]);
        $participant = User::factory()->create();
        $registration = \App\Models\Registration::create([
            'user_id' => $participant->id,
            'event_id' => $event->id,
            'event_name' => $event->name,
            'event_date' => $event->starts_at,
            'event_location' => $event->location,
            'registration_code' => 'TEST-REVIEWER',
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'confirmed',
        ]);
        $reviewer = User::factory()->create([
            'role' => 'officer_0',
            'staff_role_title' => 'reviewer',
            'staff_authorities' => [],
        ]);
        Sanctum::actingAs($reviewer);

        $this->postJson('/api/admin/events', [])->assertForbidden();
        $this->postJson('/api/check-ins', [
            'credential' => $registration->qr_token,
            'entrance_token' => $event->checkInQrToken(),
        ])->assertForbidden();
        $this->getJson('/api/admin/reports')->assertOk();
    }

    public function test_staff_authorities_grant_only_their_workflow_capabilities(): void
    {
        $approver = User::factory()->create([
            'role' => 'officer_2',
            'staff_role_title' => 'maintenance_tech',
            'staff_authorities' => ['approver'],
        ]);

        $this->assertTrue($approver->canApproveWorkflows());
        $this->assertTrue($approver->canPerformCheckIn());
        $this->assertFalse($approver->canManageEvents());
    }

    public function test_master_admin_cannot_change_own_role_or_delete_self(): void
    {
        $me = $this->master();
        Sanctum::actingAs($me);

        $this->putJson("/api/admin/admin-accounts/{$me->id}", ['role' => 'event_admin'])->assertUnprocessable();
        $this->deleteJson("/api/admin/admin-accounts/{$me->id}")->assertUnprocessable();
        $this->assertSame('admin', $me->fresh()->role);
    }

    public function test_attendee_accounts_cannot_be_edited_or_deleted_here(): void
    {
        Sanctum::actingAs($this->master());
        $attendee = User::factory()->create(['role' => 'user']);

        $this->putJson("/api/admin/admin-accounts/{$attendee->id}", ['role' => 'admin'])->assertNotFound();
        $this->deleteJson("/api/admin/admin-accounts/{$attendee->id}")->assertNotFound();
    }
}
