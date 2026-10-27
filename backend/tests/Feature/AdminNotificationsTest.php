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

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $name, ?User $owner = null): Event
    {
        return Event::create(['name' => $name, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(4), 'location' => 'Phnom Penh', 'capacity' => 50, 'enabled_fields' => [], 'status' => 'published', 'created_by' => $owner?->id]);
    }

    private function register(Event $event, string $name, $at): Registration
    {
        $registration = Registration::create(['user_id' => User::factory()->create()->id, 'event_id' => $event->id, 'event_name' => $event->name, 'event_date' => $event->starts_at, 'event_location' => $event->location, 'full_name' => $name, 'qr_token' => (string) Str::uuid(), 'status' => 'confirmed']);
        $registration->forceFill(['created_at' => $at])->save();
        return $registration;
    }

    public function test_lists_registrations_and_check_ins_newest_first(): void
    {
        $event = $this->event('Climate Forum');
        $first = $this->register($event, 'Sokha Leng', now()->subHours(3));
        $this->register($event, 'Dara Chan', now()->subHour());
        CheckIn::create(['registration_id' => $first->id, 'checked_in_at' => now()->subMinutes(5)]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/notifications')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'check_in')
            ->assertJsonPath('data.0.title', 'Sokha Leng checked in')
            ->assertJsonPath('data.0.event', 'Climate Forum')
            ->assertJsonPath('data.1.title', 'Dara Chan registered')
            ->assertJsonPath('data.2.title', 'Sokha Leng registered');
    }

    public function test_event_admins_only_see_their_own_events(): void
    {
        $owner = User::factory()->create(['role' => 'event_admin']);
        $this->register($this->event('Mine', $owner), 'Visible Person', now());
        $this->register($this->event('Someone else'), 'Hidden Person', now());

        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/notifications')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Visible Person registered');
    }

    public function test_attendees_cannot_read_notifications(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));
        $this->getJson('/api/admin/notifications')->assertForbidden();
    }
}
