<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReportsTest extends TestCase
{
    use RefreshDatabase;

    private function event(array $extra = []): Event
    {
        return Event::create([
            'name' => 'Community Tech Summit',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(8),
            'location' => 'Phnom Penh',
            'capacity' => 50,
            'enabled_fields' => [],
            'status' => 'published',
            ...$extra,
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
            'qr_token' => (string) Str::uuid(),
            'status' => 'confirmed',
            ...$extra,
        ]);
    }

    public function test_reports_require_an_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/reports')->assertForbidden();
    }

    public function test_reports_return_per_event_registration_and_check_in_counts(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $event = $this->event();

        $attendees = User::factory()->count(4)->create();
        $attendees->take(2)->each(fn (User $attendee) => $this->register($attendee, $event));
        $attendees->slice(2)->each(function (User $attendee) use ($event) {
            $registration = $this->register($attendee, $event);
            \App\Models\CheckIn::create([
                'registration_id' => $registration->id,
                'checked_in_at' => now(),
                'checked_in_by' => null,
            ]);
        });

        $response = $this->getJson('/api/admin/reports')->assertOk();

        $report = collect($response->json('data'))->firstWhere('id', $event->id);
        $this->assertNotNull($report, 'Each event should have a report row.');
        $this->assertSame(4, $report['registrations']);
        $this->assertSame(2, $report['checked_in']);
        $this->assertSame(2, $report['no_show']);
        $this->assertSame(50.0, (float) $report['attendance_rate']);
        $this->assertSame(8.0, (float) $report['capacity_used']);
    }

    public function test_reports_handle_events_without_registrations(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $event = $this->event();

        $response = $this->getJson('/api/admin/reports')->assertOk();

        $report = collect($response->json('data'))->firstWhere('id', $event->id);
        $this->assertSame(0, $report['registrations']);
        $this->assertSame(0, $report['checked_in']);
        $this->assertSame(0, $report['no_show']);
        $this->assertSame(0.0, (float) $report['attendance_rate']);
    }

    public function test_reports_are_ordered_most_recent_start_first(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $later = $this->event(['name' => 'Later Event', 'starts_at' => now()->addMonth()]);
        $sooner = $this->event(['name' => 'Sooner Event', 'starts_at' => now()->addDay()]);

        $names = collect($this->getJson('/api/admin/reports')->json('data'))->pluck('name');

        $this->assertSame([$later->name, $sooner->name], $names->all());
    }

    public function test_event_report_attendees_show_check_in_state(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $event = $this->event();

        $checkedInAttendee = User::factory()->create(['name' => 'Sokha Leng']);
        $checkedInRegistration = $this->register($checkedInAttendee, $event, ['full_name' => 'Sokha Leng', 'email' => 'sokha@example.com']);
        \App\Models\CheckIn::create(['registration_id' => $checkedInRegistration->id, 'checked_in_at' => now(), 'checked_in_by' => null]);

        $waitingAttendee = User::factory()->create(['name' => 'Dara Chan']);
        $this->register($waitingAttendee, $event, ['full_name' => 'Dara Chan', 'email' => 'dara@example.com']);

        $response = $this->getJson("/api/admin/events/{$event->id}/report-attendees")->assertOk();

        $this->assertSame($event->id, $response->json('data.event.id'));
        $this->assertSame(2, $response->json('data.summary.total'));
        $this->assertSame(1, $response->json('data.summary.checked_in'));
        $this->assertSame(1, $response->json('data.summary.not_checked_in'));

        $attendees = collect($response->json('data.attendees'));
        $sokha = $attendees->firstWhere('name', 'Sokha Leng');
        $dara = $attendees->firstWhere('name', 'Dara Chan');
        $this->assertNotNull($sokha['checked_in_at']);
        $this->assertNull($dara['checked_in_at']);
    }

    public function test_event_report_attendees_include_profile_and_form_answers(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $event = $this->event();
        $shirt = \App\Models\FormField::create(['event_id' => $event->id, 'label' => 'T-shirt Size', 'type' => 'select', 'options' => ['S', 'M', 'L'], 'sort_order' => 0]);

        $this->register(User::factory()->create(), $event, [
            'full_name' => 'Sokha Leng',
            'email' => 'sokha@example.com',
            'gender' => 'female',
            'age' => 28,
            'organization' => 'NGO Cambodia',
            'position' => 'Coordinator',
            'form_data' => [$shirt->id => 'M'],
        ]);

        $attendee = collect($this->getJson("/api/admin/events/{$event->id}/report-attendees")->json('data.attendees'))->first();

        $this->assertSame('female', $attendee['profile']['gender']);
        $this->assertSame(28, $attendee['profile']['age']);
        $this->assertSame('NGO Cambodia', $attendee['profile']['organization']);
        $this->assertSame('Coordinator', $attendee['profile']['position']);
        $this->assertSame('M', $attendee['form_answers']['T-shirt Size']);
    }

    public function test_event_report_attendees_filter_by_check_in_state_and_search(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $event = $this->event();

        $checkedInRegistration = $this->register(User::factory()->create(), $event, ['full_name' => 'Sokha Leng', 'email' => 'sokha@example.com']);
        \App\Models\CheckIn::create(['registration_id' => $checkedInRegistration->id, 'checked_in_at' => now(), 'checked_in_by' => null]);
        $this->register(User::factory()->create(), $event, ['full_name' => 'Dara Chan', 'email' => 'dara@example.com']);

        $checkedIn = $this->getJson("/api/admin/events/{$event->id}/report-attendees?check_in=in")->json('data.attendees');
        $this->assertCount(1, $checkedIn);
        $this->assertSame('Sokha Leng', $checkedIn[0]['name']);

        $notCheckedIn = $this->getJson("/api/admin/events/{$event->id}/report-attendees?check_in=out")->json('data.attendees');
        $this->assertCount(1, $notCheckedIn);
        $this->assertSame('Dara Chan', $notCheckedIn[0]['name']);

        $searched = $this->getJson("/api/admin/events/{$event->id}/report-attendees?search=dara")->json('data.attendees');
        $this->assertCount(1, $searched);
        $this->assertSame('Dara Chan', $searched[0]['name']);
    }

    public function test_event_report_attendees_require_an_admin(): void
    {
        $event = $this->event();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/admin/events/{$event->id}/report-attendees")->assertForbidden();
    }
}
