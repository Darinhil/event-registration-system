<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Sanctum::actingAs($this->master());

        $response = $this->postJson('/api/admin/admin-accounts', ['name' => 'Dara Sok', 'email' => 'dara@example.test', 'password' => 'secret-pass', 'role' => 'manager'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'manager');

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertDatabaseHas('users', ['email' => 'dara@example.test', 'role' => 'manager']);
    }

    public function test_create_validates_email_password_and_role(): void
    {
        Sanctum::actingAs($this->master());
        User::factory()->create(['email' => 'taken@example.test']);

        $this->postJson('/api/admin/admin-accounts', ['name' => 'X', 'email' => 'taken@example.test', 'password' => 'short', 'role' => 'superuser'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'role']);
    }

    public function test_only_master_admin_can_manage_accounts(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'event_admin']));

        $this->getJson('/api/admin/admin-accounts')->assertForbidden();
        $this->postJson('/api/admin/admin-accounts', ['name' => 'X', 'email' => 'x@example.test', 'password' => 'secret-pass', 'role' => 'admin'])->assertForbidden();
    }

    public function test_master_admin_changes_another_accounts_role(): void
    {
        Sanctum::actingAs($this->master());
        $member = User::factory()->create(['role' => 'event_admin']);

        $this->putJson("/api/admin/admin-accounts/{$member->id}", ['role' => 'officer_2'])->assertOk()->assertJsonPath('data.role', 'officer_2');
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
