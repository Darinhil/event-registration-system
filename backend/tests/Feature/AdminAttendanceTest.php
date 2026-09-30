<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function event(): Event
    {
        return Event::create([
            'name' => 'Community Tech Summit',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(8),
            'location' => 'Phnom Penh',
            'capacity' => 50,
            'enabled_fields' => [],
            'status' => 'published',
        ]);
    }

    private function register(User $participant, Event $event, array $extra = []): Registration
    {
        return Registration::create([
            'user_id' => $participant->id,
            'event_id' => $event->id,
            'event_name' => $event->name,
            'event_date' => $event->starts_at,
            'event_location' => $event->location,
            'qr_token' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'confirmed',
            ...$extra,
        ]);
    }

    public function test_users_endpoint_returns_form_values_for_submitted_form_data(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->event();
        $gender = FormField::create(['event_id' => $event->id, 'label' => 'Gender', 'type' => 'radio', 'required' => true, 'options' => ['Male', 'Female'], 'sort_order' => 0]);
        $shirt = FormField::create(['event_id' => $event->id, 'label' => 'T-shirt Size', 'type' => 'select', 'required' => false, 'options' => ['S', 'M', 'L'], 'sort_order' => 1]);

        $participant = User::factory()->create();
        $registration = $this->register($participant, $event, [
            'form_data' => [
                $gender->id => 'Female',
                $shirt->id => 'M',
                'orphan_key' => 'legacy answer',
            ],
            'full_name' => 'Sokha Leng',
            'gender' => 'female',
            'email' => 'sokha@example.com',
        ]);

        $response = $this->getJson('/api/admin/users?event_id=' . $event->id);

        $response->assertOk();
        $user = collect($response->json('data'))->firstWhere('id', $participant->id);
        $this->assertNotNull($user, 'Participant should be returned for the event.');
        $this->assertSame($event->id, $user['registrations'][0]['event_id']);

        $formValues = $user['registrations'][0]['form_values'];
        $this->assertSame('Female', $formValues['Gender']);
        $this->assertSame('M', $formValues['T-shirt Size']);
        $this->assertSame('legacy answer', $formValues['orphan_key']);
    }

    public function test_users_endpoint_filters_by_event(): void
    {
        Sanctum::actingAs($this->admin());
        $eventA = $this->event();
        $eventB = Event::create([
            'name' => 'Other Event',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHours(4),
            'location' => 'Siem Reap',
            'capacity' => 50,
            'enabled_fields' => [],
            'status' => 'published',
        ]);

        $participant = User::factory()->create();
        foreach ([$eventA, $eventB] as $event) {
            $this->register($participant, $event, ['full_name' => 'Dara Chan', 'email' => 'dara@example.com']);
        }

        $response = $this->getJson('/api/admin/users?event_id=' . $eventB->id);

        $response->assertOk();
        $users = collect($response->json('data'));
        $this->assertSame(1, $users->count(), 'Only users registered for the requested event should be listed.');
        $returned = $users->firstWhere('id', $participant->id);
        $this->assertNotNull($returned);
        $this->assertSame(1, $returned['registrations_count']);
        $this->assertSame($eventB->id, $returned['registrations'][0]['event_id']);
    }

    public function test_empty_form_answers_are_omitted_from_form_values(): void
    {
        Sanctum::actingAs($this->admin());
        $event = $this->event();
        $dietary = FormField::create(['event_id' => $event->id, 'label' => 'Dietary Needs', 'type' => 'text', 'required' => false, 'sort_order' => 0]);

        $participant = User::factory()->create();
        $this->register($participant, $event, [
            'form_data' => [$dietary->id => '', 'leftover' => null],
            'full_name' => 'Ravy Sot',
            'email' => 'ravy@example.com',
        ]);

        $response = $this->getJson('/api/admin/users?event_id=' . $event->id);

        $response->assertOk();
        $user = collect($response->json('data'))->firstWhere('id', $participant->id);
        $this->assertSame([], $user['registrations'][0]['form_values']);
    }
}
