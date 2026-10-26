<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventDraftFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        return $admin;
    }

    private function fullEvent(array $overrides = []): array
    {
        return [
            'name' => 'Youth Climate Forum',
            'category' => 'Conference',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeek()->addHours(8)->toIso8601String(),
            'location' => 'PNC Campus, Phnom Penh',
            'capacity' => 150,
            'form_config' => ['settings' => ['registration_end' => now()->addDays(6)->toDateString()], 'agenda' => []],
            'form_fields' => [['label' => 'Full name', 'type' => 'text', 'required' => true]],
            ...$overrides,
        ];
    }

    private function registration(Event $event): array
    {
        return ['event_id' => $event->id, 'full_name' => 'Sokha Leng', 'gender' => 'female', 'age' => 20, 'phone' => '097 123 456', 'email' => 'sokha@example.com', 'organization' => 'NGO Cambodia'];
    }

    public function test_draft_can_be_saved_with_only_a_name(): void
    {
        $this->admin();

        $response = $this->postJson('/api/admin/events', ['name' => 'Untitled planning draft', 'status' => 'draft'])->assertCreated();

        $event = Event::findOrFail($response->json('id'));
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->starts_at);
        $this->assertNull($event->capacity);
    }

    public function test_publishing_still_requires_schedule_location_and_capacity(): void
    {
        $this->admin();

        $this->postJson('/api/admin/events', ['name' => 'Incomplete event'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at', 'ends_at', 'location', 'capacity']);
    }

    public function test_events_are_published_by_default_for_existing_clients(): void
    {
        $this->admin();

        $response = $this->postJson('/api/admin/events', $this->fullEvent())->assertCreated();

        $this->assertSame('published', Event::findOrFail($response->json('id'))->status);
    }

    public function test_draft_can_be_completed_and_published_later(): void
    {
        $this->admin();
        $id = $this->postJson('/api/admin/events', ['name' => 'Draft first', 'status' => 'draft'])->json('id');

        $this->putJson("/api/admin/events/{$id}", ['name' => 'Draft first', 'status' => 'published'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['starts_at', 'location']);

        $this->putJson("/api/admin/events/{$id}", $this->fullEvent(['status' => 'published']))
            ->assertOk()
            ->assertJsonPath('status', 'published');
        $this->assertSame(1, Event::findOrFail($id)->formFields()->count());
    }

    public function test_published_event_cannot_be_moved_back_to_draft(): void
    {
        $this->admin();
        $id = $this->postJson('/api/admin/events', $this->fullEvent())->json('id');

        $this->putJson("/api/admin/events/{$id}", ['status' => 'draft'])->assertStatus(422);
        $this->assertSame('published', Event::findOrFail($id)->status);
    }

    public function test_editing_a_published_event_keeps_it_published(): void
    {
        $this->admin();
        $id = $this->postJson('/api/admin/events', $this->fullEvent())->json('id');

        $this->putJson("/api/admin/events/{$id}", ['name' => 'Renamed forum'])->assertOk()->assertJsonPath('status', 'published');
    }

    public function test_draft_events_do_not_accept_registrations(): void
    {
        $this->admin();
        $draftId = $this->postJson('/api/admin/events', $this->fullEvent(['status' => 'draft', 'form_fields' => []]))->json('id');
        $publishedId = $this->postJson('/api/admin/events', $this->fullEvent(['form_fields' => []]))->json('id');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/registrations', $this->registration(Event::findOrFail($draftId)))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event_id' => 'not open']);
        // Same payload succeeds once the event is published, so the rejection above is the status check.
        $this->postJson('/api/registrations', $this->registration(Event::findOrFail($publishedId)))->assertCreated();
    }

    public function test_registration_can_be_paused_with_allow_registration(): void
    {
        $this->admin();
        $id = $this->postJson('/api/admin/events', $this->fullEvent([
            'form_config' => ['settings' => ['allow_registration' => false], 'agenda' => []],
            'form_fields' => [],
        ]))->json('id');
        $event = Event::findOrFail($id);

        $this->getJson("/api/events/{$id}/form")->assertOk()->assertJsonPath('data.registration_open', false);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/registrations', $this->registration($event))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event_id' => 'currently closed']);
    }

    public function test_organiser_can_reopen_their_draft_but_it_stays_private(): void
    {
        $owner = User::factory()->create(['role' => 'event_admin']);
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/admin/events', ['name' => 'Private draft', 'status' => 'draft'])->json('id');

        $this->getJson("/api/events/{$id}")->assertOk()->assertJsonPath('data.status', 'draft');
        $this->getJson("/api/events/{$id}/form")->assertOk();

        Sanctum::actingAs(User::factory()->create(['role' => 'event_admin']));
        $this->getJson("/api/events/{$id}")->assertForbidden();
    }

    public function test_guests_cannot_see_drafts(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $id = $this->postJson('/api/admin/events', ['name' => 'Hidden draft', 'status' => 'draft'])->json('id');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/events/{$id}")->assertNotFound();
        $this->getJson("/api/events/{$id}/form")->assertNotFound();
    }

    public function test_event_admin_cannot_edit_another_organisers_draft(): void
    {
        $owner = User::factory()->create(['role' => 'event_admin']);
        Sanctum::actingAs($owner);
        $id = $this->postJson('/api/admin/events', ['name' => 'Private draft', 'status' => 'draft'])->assertCreated()->json('id');

        Sanctum::actingAs(User::factory()->create(['role' => 'event_admin']));
        $this->putJson("/api/admin/events/{$id}", ['name' => 'Hijacked', 'status' => 'draft'])->assertForbidden();
    }
}
