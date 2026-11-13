<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'event_id' => $event->id,
            'form_data' => [],
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
            'event_id' => $event->id,
            'form_data' => [],
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
            'event_id' => $event->id,
            'form_data' => [],
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
            'event_id' => $event->id,
            'form_data' => [],
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

    public function test_event_admin_can_delete_a_registrant_who_has_checked_in(): void
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
            ->assertOk()
            ->assertJsonPath('message', 'Registrant deleted successfully.');

        $this->assertDatabaseMissing('registrations', ['id' => $registration->id]);
        $this->assertDatabaseMissing('check_ins', ['registration_id' => $registration->id]);
    }

    public function test_registrant_cannot_be_managed_through_a_different_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $otherEvent = $this->event($admin, 'Different Event');
        $registration = $this->registration($otherEvent);

        $this->putJson("/api/admin/events/{$event->id}/registrants/{$registration->id}", [
            'event_id' => $event->id,
            'form_data' => [],
            'full_name' => 'Changed Name',
        ])->assertNotFound();

        $this->assertDatabaseHas('registrations', [
            'id' => $registration->id,
            'full_name' => 'Sarah Jenkins',
        ]);
    }

    public function test_event_admin_can_update_custom_registration_answers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $registration = $this->registration($event);
        $organization = FormField::create([
            'event_id' => $event->id,
            'label' => 'Organization',
            'type' => 'text',
            'required' => true,
            'options' => [],
            'settings' => [],
            'sort_order' => 1,
        ]);
        $customField = FormField::create([
            'event_id' => $event->id,
            'label' => 'Why are you attending?',
            'type' => 'textarea',
            'required' => false,
            'options' => [],
            'settings' => [],
            'sort_order' => 2,
        ]);

        $this->putJson("/api/admin/events/{$event->id}/registrants/{$registration->id}", [
            'event_id' => $event->id,
            'form_data' => [
                $organization->id => 'Community Lab',
                $customField->id => 'To meet local organizers.',
            ],
            'full_name' => 'Sarah Jenkins',
            'email' => 'sarah@example.com',
            'phone' => '+855 12 345 678',
            'gender' => 'female',
            'age' => 28,
            'organization' => 'Community Lab',
        ])->assertOk()->assertJsonPath('message', 'Registrant updated successfully.');

        $updated = $registration->fresh();
        $this->assertSame('Community Lab', $updated->organization);
        $this->assertSame('Community Lab', $updated->form_data[(string) $organization->id]);
        $this->assertSame('To meet local organizers.', $updated->form_data[(string) $customField->id]);
    }

    public function test_admin_edit_can_replace_a_registration_file_answer(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $event = $this->event($admin);
        $fileField = FormField::create([
            'event_id' => $event->id,
            'label' => 'Supporting document',
            'type' => 'file',
            'required' => false,
            'options' => [],
            'settings' => ['allowed_types' => ['pdf'], 'max_size_mb' => 2],
            'sort_order' => 1,
        ]);
        Storage::disk('public')->put('form-uploads/old.pdf', 'old file');
        $registration = $this->registration($event);
        $registration->update(['form_data' => [$fileField->id => 'form-uploads/old.pdf']]);

        $this->post("/api/admin/events/{$event->id}/registrants/{$registration->id}", [
            '_method' => 'PUT',
            'event_id' => $event->id,
            'form_data' => [$fileField->id => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')],
            'full_name' => 'Sarah Jenkins',
        ])->assertOk();

        $updatedPath = $registration->fresh()->form_data[(string) $fileField->id];
        $this->assertNotSame('form-uploads/old.pdf', $updatedPath);
        Storage::disk('public')->assertExists($updatedPath);
        Storage::disk('public')->assertMissing('form-uploads/old.pdf');
    }
}
