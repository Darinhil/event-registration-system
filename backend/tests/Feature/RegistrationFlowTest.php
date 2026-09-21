<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function event(int $capacity = 2): Event
    {
        return Event::create(['name' => 'Climate Youth Forum 2025', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(8), 'location' => 'PNC Campus, Phnom Penh', 'capacity' => $capacity, 'enabled_fields' => ['address' => false, 'position' => false, 'profile_photo' => false, 'emergency_contact' => false], 'status' => 'published']);
    }

    private function payload(Event $event, string $email): array
    {
        return ['event_id' => $event->id, 'full_name' => 'Sokha Leng', 'gender' => 'female', 'age' => 20, 'phone' => '097 123 456', 'email' => $email, 'organization' => 'NGO Cambodia'];
    }

    public function test_registration_requires_core_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $response = $this->postJson('/api/registrations', ['event_id' => $this->event()->id]);
        $response->assertStatus(422)->assertJsonValidationErrors(['full_name', 'gender', 'age', 'phone', 'email', 'organization']);
    }

    public function test_email_cannot_register_twice_for_same_event(): void
    {
        $user = User::factory()->create(); $event = $this->event(); Sanctum::actingAs($user);
        $this->postJson('/api/registrations', $this->payload($event, 'sokha@gmail.com'))->assertCreated()->assertJsonPath('data.registration_code', 'CLIMATE-YOUTH-FORUM-2025-001')->assertJsonPath('data.qr_token', fn (string $token): bool => (bool) preg_match('/^[0-9a-f-]{36}$/', $token));
        $this->postJson('/api/registrations', $this->payload($event, 'sokha@gmail.com'))->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_event_capacity_is_enforced(): void
    {
        $event = $this->event(1); Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/registrations', $this->payload($event, 'first@example.com'))->assertCreated();
        $this->postJson('/api/registrations', $this->payload($event, 'second@example.com'))->assertStatus(422)->assertJsonValidationErrors('event_id');
    }
}