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

class MyRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_answers_keep_their_field_ids_for_editing(): void
    {
        $user = User::factory()->create();
        $registration = $this->registration($user);
        $registration->update(['form_data' => ['1' => 'Dara Chan', '2' => 'dara@example.com', '3' => 'L']]);
        Sanctum::actingAs($user);

        $this->getJson("/api/registrations/{$registration->id}")->assertOk()
            ->assertJsonPath('data.form_data.1', 'Dara Chan')
            ->assertJsonPath('data.form_data.2', 'dara@example.com')
            ->assertJsonPath('data.form_data.3', 'L');
    }

    private function registration(User $user, array $event = []): Registration
    {
        $model = Event::create(array_merge(['name' => 'Forum', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(8), 'location' => 'Phnom Penh', 'capacity' => 10, 'enabled_fields' => [], 'status' => 'published'], $event));
        return Registration::create(['user_id' => $user->id, 'event_id' => $model->id, 'registration_code' => 'R-'.Str::random(6), 'event_name' => $model->name, 'event_date' => now()->subMonth(), 'event_location' => 'Old venue', 'full_name' => 'Sokha', 'email' => $user->email, 'qr_token' => (string) Str::uuid(), 'status' => 'confirmed']);
    }

    public function test_upcoming_registration_can_be_cancelled(): void
    {
        $user = User::factory()->create(); Sanctum::actingAs($user);
        $registration = $this->registration($user);

        $this->getJson('/api/me')->assertJsonPath('data.registrations.0.can_cancel', true);
        $this->deleteJson("/api/registrations/{$registration->id}")->assertOk();
        $this->assertModelMissing($registration);
    }

    public function test_checked_in_registration_cannot_be_deleted(): void
    {
        $user = User::factory()->create(); Sanctum::actingAs($user);
        $registration = $this->registration($user);
        CheckIn::create(['registration_id' => $registration->id, 'checked_in_at' => now()]);

        $this->getJson('/api/me')->assertJsonPath('data.registrations.0.can_cancel', false);
        $this->deleteJson("/api/registrations/{$registration->id}")->assertStatus(422);
        $this->assertModelExists($registration);
    }

    public function test_past_event_registration_cannot_be_deleted(): void
    {
        $user = User::factory()->create(); Sanctum::actingAs($user);
        $registration = $this->registration($user, ['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

        $this->deleteJson("/api/registrations/{$registration->id}")->assertStatus(422);
        $this->assertModelExists($registration);
    }

    public function test_profile_shows_the_events_current_schedule_and_venue(): void
    {
        $user = User::factory()->create(); Sanctum::actingAs($user);
        $registration = $this->registration($user, ['location' => 'New venue']);

        $this->getJson('/api/me')
            ->assertJsonPath('data.registrations.0.event_current_location', 'New venue')
            ->assertJsonPath('data.registrations.0.event_starts_at', $registration->event->starts_at->toIso8601String());
    }
}
