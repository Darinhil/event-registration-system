<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRegistrantManagementTest extends TestCase
{
    use RefreshDatabase;

    private function event(User $admin, string $name = 'Community Tech Summit'): Event
    {
        return Event::create([
            'name' => $name,
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(8),
            'location' => 'Phnom Penh',
            'capacity' => 50,
            'enabled_fields' => [],
            'status' => 'published',
            'created_by' => $admin->id,
        ]);
    }

    private function registration(Event $event): Registration
    {
        return Registration::create([
            'user_id' => User::factory()->create()->id,
            'event_id' => $event->id,
            'event_name' => $event->name,
            'event_date' => $event->starts_at,
            'event_location' => $event->location,
            'full_name' => 'Sarah Jenkins',
            'gender' => 'female',
            'age' => 28,
            'qr_token' => (string) Str::uuid(),
            'status' => 'confirmed',
        ]);
    }

    public function test_event_admin_can_update_registrant_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $registration = $this->registration($event);

        $this->putJson("/api/admin/events/{$event->id}/registrants/{$registration->id}", [
            'full_name' => 'Sarah J. Jenkins',
            'gender' => 'female',
            'age' => 29,
            'phone' => '+855 12 345 678',
            'email' => 'sarah@example.com',
        ])->assertOk()->assertJsonPath('message', 'Registrant updated successfully.');

        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'full_name' => 'Sarah J. Jenkins',
            'age' => 29,
            'phone' => '+855 12 345 678',
            'email' => 'sarah@example.com',
        ]);
    }

    public function test_event_admin_can_manage_registrants_for_their_own_event(): void
    {
        $admin = User::factory()->create(['role' => 'event_admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $registration = $this->registration($event);

        $this->putJson("/api/admin/events/{$event->id}/registrants/{$registration->id}", [
            'full_name' => 'Updated Attendee',
        ])->assertOk();

        $this->assertDatabaseHas('registrations', ['id' => $registration->id, 'full_name' => 'Updated Attendee']);
    }

    public function test_event_admin_cannot_manage_registrants_for_another_organisers_event(): void
    {
        $admin = User::factory()->create(['role' => 'event_admin']);
        $otherAdmin = User::factory()->create(['role' => 'event_admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($otherAdmin);
        $registration = $this->registration($event);

        $this->putJson("/api/admin/events/{$event->id}/registrants/{$registration->id}", [
            'full_name' => 'Unauthorized Change',
        ])->assertForbidden();

        $this->assertDatabaseHas('registrations', ['id' => $registration->id, 'full_name' => 'Sarah Jenkins']);
    }

    public function test_registrant_update_validates_profile_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $registration = $this->registration($event);

        $this->putJson("/api/admin/events/{$event->id}/registrants/{$registration->id}", [
            'full_name' => 'S',
            'age' => 121,
            'email' => 'not-an-email',
        ])->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'age', 'email']);
    }

    public function test_event_admin_can_delete_a_registrant_who_has_not_checked_in(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $registration = $this->registration($event);

        $this->deleteJson("/api/admin/events/{$event->id}/registrants/{$registration->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Registrant deleted successfully.');

        $this->assertDatabaseMissing('registrations', ['id' => $registration->id]);
    }

    public function test_registrant_with_check_in_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $registration = $this->registration($event);
        CheckIn::create([
            'registration_id' => $registration->id,
            'checked_in_at' => now(),
            'checked_in_by' => $admin->id,
        ]);

        $this->deleteJson("/api/admin/events/{$event->id}/registrants/{$registration->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('registrations', ['id' => $registration->id]);
    }

    public function test_registrant_cannot_be_managed_through_a_different_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $otherEvent = $this->event($admin, 'Different Event');
        $registration = $this->registration($otherEvent);

        $this->putJson("/api/admin/events/{$event->id}/registrants/{$registration->id}", [
            'full_name' => 'Changed Name',
        ])->assertNotFound();

        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'full_name' => 'Sarah Jenkins',
        ]);
    }
}
