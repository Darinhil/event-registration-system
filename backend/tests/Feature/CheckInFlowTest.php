<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckInFlowTest extends TestCase
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

    public function test_preview_returns_form_answers_for_a_scanned_token(): void
    {
        $attendee = User::factory()->create();
        $event = $this->event();
        $shirt = FormField::create(['event_id' => $event->id, 'label' => 'T-shirt Size', 'type' => 'select', 'options' => ['S', 'M', 'L'], 'sort_order' => 0]);
        $registration = $this->register($attendee, $event, [
            'full_name' => 'Sokha Leng',
            'email' => 'sokha@example.com',
            'form_data' => [$shirt->id => 'M'],
        ]);
        Sanctum::actingAs($attendee);

        $response = $this->postJson('/api/check-ins/preview', ['qr_token' => $registration->qr_token]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('Sokha Leng', $data['full_name']);
        $this->assertSame($event->id, $data['event_id']);
        $this->assertSame('M', $data['form_values']['T-shirt Size']);
    }

    public function test_preview_rejects_unknown_tokens(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/check-ins/preview', ['qr_token' => (string) Str::uuid()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qr_token');
    }

    public function test_attendee_cannot_preview_another_attendees_registration(): void
    {
        $event = $this->event();
        $registration = $this->register(User::factory()->create(), $event, ['full_name' => 'Someone Else']);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/check-ins/preview', ['qr_token' => $registration->qr_token])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/check-ins/preview', ['qr_token' => $registration->qr_token])->assertOk();
    }

    public function test_admin_lookup_returns_form_answers_and_staff_route_is_protected(): void
    {
        $event = $this->event();
        $org = FormField::create(['event_id' => $event->id, 'label' => 'Organization', 'type' => 'text', 'sort_order' => 0]);
        $registration = $this->register(User::factory()->create(), $event, [
            'full_name' => 'Dara Chan',
            'form_data' => [$org->id => 'NGO Cambodia'],
        ]);

        // Attendees must not reach the admin lookup.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/admin/check-ins/lookup', ['qr_token' => $registration->qr_token])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->postJson('/api/admin/check-ins/lookup', ['qr_token' => $registration->qr_token]);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('Dara Chan', $data['full_name']);
        $this->assertSame('NGO Cambodia', $data['form_values']['Organization']);
        $this->assertArrayNotHasKey('form_data', $data);
    }

    public function test_admin_lookup_accepts_the_attendee_pass_qr_and_json_qr_payload(): void
    {
        $event = $this->event();
        $registration = $this->register(User::factory()->create(), $event, [
            'full_name' => 'QR Attendee',
            'registration_code' => 'QR-ATTENDEE-001',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        // The attendee pass renders qr_token directly as the QR value.
        $this->getJson('/api/admin/check-ins/lookup?'.http_build_query(['credential' => $registration->qr_token]))
            ->assertOk()
            ->assertJsonPath('data.id', $registration->id)
            ->assertJsonPath('data.registration_code', 'QR-ATTENDEE-001');

        // Support QR payloads created by QRCodeService as well.
        $payload = app(\App\Services\QRCodeService::class)->payload($registration);
        $this->getJson('/api/admin/check-ins/lookup?'.http_build_query(['credential' => $payload]))
            ->assertOk()
            ->assertJsonPath('data.id', $registration->id);
    }

    public function test_attendee_can_check_in_and_admin_sees_them_in_the_log(): void
    {
        $attendee = User::factory()->create();
        $event = $this->event();
        $registration = $this->register($attendee, $event, ['full_name' => 'Sokha Leng']);
        Sanctum::actingAs($attendee);

        $this->postJson('/api/check-ins', ['qr_token' => $registration->qr_token])
            ->assertCreated()
            ->assertJsonPath('check_in.registration_id', $registration->id);

        // A second scan of the same token must not create a duplicate.
        $this->postJson('/api/check-ins', ['qr_token' => $registration->qr_token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qr_token');

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $response = $this->getJson("/api/admin/events/{$event->id}/check-ins");

        $response->assertOk();
        $payload = $response->json('data');
        $this->assertSame($event->id, $payload['event']['id']);
        $this->assertSame(1, $payload['summary']['expected']);
        $this->assertSame(1, $payload['summary']['checked_in']);
        $this->assertSame(0, $payload['summary']['remaining']);
        $this->assertSame('Sokha Leng', $payload['check_ins'][0]['name']);
        $this->assertSame('Sokha Leng', $payload['recent_registrations'][0]['name']);
    }

    public function test_attendee_cannot_check_in_someone_elses_registration(): void
    {
        $event = $this->event();
        $registration = $this->register(User::factory()->create(), $event);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/check-ins', ['credential' => $registration->qr_token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credential');
        $this->assertDatabaseMissing('check_ins', ['registration_id' => $registration->id]);
    }

    public function test_check_in_staff_must_have_permission_and_own_the_event(): void
    {
        $staff = User::factory()->create(['role' => 'maker']);
        $event = $this->event();
        $registration = $this->register(User::factory()->create(), $event);
        Sanctum::actingAs($staff);

        $this->postJson('/api/check-ins', ['credential' => $registration->qr_token])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credential');

        $this->grantCheckInPermission('maker');
        $staffEvent = $this->event(['created_by' => $staff->id]);
        $staffRegistration = $this->register(User::factory()->create(), $staffEvent);
        $this->postJson('/api/check-ins', ['credential' => $staffRegistration->qr_token])
            ->assertCreated()
            ->assertJsonPath('check_in.registration_id', $staffRegistration->id);
    }

    public function test_check_in_manager_can_load_and_check_in_events_created_by_other_staff(): void
    {
        $this->grantCheckInPermission('maker');
        $manager = User::factory()->create(['role' => 'maker']);
        $organizer = User::factory()->create(['role' => 'maker']);
        $event = $this->event(['created_by' => $organizer->id]);
        $registration = $this->register(User::factory()->create(), $event, ['registration_code' => 'SHARED-001']);
        Sanctum::actingAs($manager);

        $eventsResponse = $this->getJson('/api/admin/events')
            ->assertOk()
            ->assertJsonFragment(['id' => $event->id, 'name' => $event->name]);
        $this->assertTrue(array_is_list($eventsResponse->json()), 'The admin events endpoint returns a plain event list.');
        $eventResponse = $this->getJson("/api/events/{$event->id}")->assertOk();
        $entranceToken = $eventResponse->json('data.check_in_qr_token');
        $this->assertTrue(Str::isUuid($entranceToken), 'The event check-in page receives a valid QR token.');
        $attendanceResponse = $this->getJson("/api/admin/events/{$event->id}/check-ins")->assertOk();
        $this->assertSame($entranceToken, $attendanceResponse->json('data.event.check_in_qr_token'));
        $this->getJson("/api/admin/events/{$event->id}/registrants")->assertOk();
        $this->postJson('/api/admin/check-ins/search', ['event_id' => $event->id, 'query' => 'SHARED-001'])
            ->assertOk()
            ->assertJsonPath('data.registration.id', $registration->id);
        $this->postJson('/api/check-ins', ['credential' => $registration->qr_token])
            ->assertCreated()
            ->assertJsonPath('check_in.registration_id', $registration->id);
    }

    public function test_registration_lookup_is_restricted_to_check_in_staff_and_requires_a_credential(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/check-ins/lookup?credential=anything')->assertNotFound();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/check-ins/lookup')->assertUnprocessable()->assertJsonValidationErrors(['credential', 'qr_token']);
    }

    public function test_event_check_in_log_requires_admin(): void
    {
        $event = $this->event();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/admin/events/{$event->id}/check-ins")->assertForbidden();
    }

    public function test_attendee_can_preview_via_event_id_or_entrance_token(): void
    {
        $attendee = User::factory()->create();
        $event = $this->event();
        $registration = $this->register($attendee, $event, ['full_name' => 'Sokha Leng']);
        Sanctum::actingAs($attendee);

        // By plain event_id (deep link fallback).
        $this->postJson('/api/check-ins/preview', ['event_id' => $event->id])
            ->assertOk()
            ->assertJsonPath('data.id', $registration->id);

        // By the regenerable entrance token embedded in the QR URL.
        $token = $event->checkInQrToken();
        $this->postJson('/api/check-ins/preview', ['entrance_token' => $token])
            ->assertOk()
            ->assertJsonPath('data.id', $registration->id);

        // A different attendee with no registration gets a clear 404.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/check-ins/preview', ['event_id' => $event->id])->assertNotFound();
    }

    public function test_admin_can_regenerate_the_entrance_qr_token(): void
    {
        $event = $this->event();
        $oldToken = $event->checkInQrToken();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson("/api/admin/events/{$event->id}/check-in-qr")
            ->assertOk();

        $newToken = $event->fresh()->check_in_qr_token;
        $this->assertNotSame($oldToken, $newToken);

        // Old printed token no longer resolves; new one does.
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/check-ins/preview', ['entrance_token' => $oldToken])
            ->assertStatus(422)
            ->assertJsonValidationErrors('qr_token');
    }

    public function test_desk_search_finds_attendee_by_registration_code(): void
    {
        $event = $this->event();
        $registration = $this->register(User::factory()->create(), $event, [
            'full_name' => 'Sokha Leng',
            'email' => 'sokha@example.com',
            'registration_code' => 'CTS-001',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        // Case-insensitive exact code.
        $response = $this->postJson('/api/admin/check-ins/search', ['event_id' => $event->id, 'query' => 'cts-001'])
            ->assertOk();
        $this->assertSame($registration->id, $response->json('data.registration.id'));
        $this->assertSame('Sokha Leng', $response->json('data.registration.full_name'));

        // Partial code also resolves when unique.
        $this->postJson('/api/admin/check-ins/search', ['event_id' => $event->id, 'query' => 'CTS-'])
            ->assertOk()
            ->assertJsonPath('data.registration.id', $registration->id);

        // Unknown code gives a helpful validation error.
        $this->postJson('/api/admin/check-ins/search', ['event_id' => $event->id, 'query' => 'NOPE-999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('query');
    }

    public function test_desk_search_suggests_when_multiple_people_match(): void
    {
        $event = $this->event();
        $this->register(User::factory()->create(), $event, ['full_name' => 'Dara Sok', 'email' => 'a@example.com', 'registration_code' => 'CTS-001']);
        $this->register(User::factory()->create(), $event, ['full_name' => 'Dara Chan', 'email' => 'b@example.com', 'registration_code' => 'CTS-002']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->postJson('/api/admin/check-ins/search', ['event_id' => $event->id, 'query' => 'Dara'])
            ->assertOk();

        $this->assertNull($response->json('data.registration'));
        $this->assertCount(2, $response->json('data.suggestions'));
    }

    public function test_desk_search_is_admin_only(): void
    {
        $event = $this->event();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/admin/check-ins/search', ['event_id' => $event->id, 'query' => 'CTS-001'])
            ->assertForbidden();
    }

    public function test_desk_search_finds_attendees_registered_through_the_api(): void
    {
        // Register through the real endpoint so the code is the generated one.
        $event = $this->event();
        $event->formFields()->create(['label' => 'Name', 'type' => 'text', 'required' => true, 'sort_order' => 0]);
        $event->formFields()->create(['label' => 'Email', 'type' => 'email', 'required' => true, 'sort_order' => 1]);
        $attendee = User::factory()->create();
        Sanctum::actingAs($attendee);
        $fields = $event->formFields()->get()->keyBy('label');
        $this->postJson('/api/registrations', ['event_id' => $event->id, 'form_data' => [
            $fields['Name']->id => 'Ravy Sot',
            $fields['Email']->id => 'ravy@example.com',
        ]])->assertCreated();

        $code = $attendee->registrations()->first()->registration_code;
        $this->assertNotNull($code, 'Registrations created via the API must carry a code.');

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/admin/check-ins/search', ['event_id' => $event->id, 'query' => $code])
            ->assertOk()
            ->assertJsonPath('data.registration.full_name', 'Ravy Sot');
    }

    private function grantCheckInPermission(string $roleKey): void
    {
        $role = Role::query()->where('key', $roleKey)->firstOrFail();
        $permissionId = Permission::query()->where('key', 'checkins.manage')->value('id');
        $role->permissions()->syncWithoutDetaching([$permissionId]);
    }
}
