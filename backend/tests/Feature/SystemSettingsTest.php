<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function event(): Event
    {
        return Event::create(['name' => 'Climate Youth Forum', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(8), 'location' => 'Phnom Penh', 'capacity' => 50, 'enabled_fields' => [], 'status' => 'published']);
    }

    private function save(array $values): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/admin/settings', [...['site_name' => 'LLC-Event', 'registration_notice' => '', 'maintenance_mode' => false], ...$values])->assertOk();
    }

    public function test_public_settings_return_defaults_without_signing_in(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertExactJson(['data' => ['site_name' => 'LLC-Event', 'registration_notice' => '', 'maintenance_mode' => false]]);
    }

    public function test_saved_values_are_trimmed_and_served_publicly(): void
    {
        $this->save(['site_name' => '  Live & Learn Events  ', 'registration_notice' => '  Bring your ID.  ', 'maintenance_mode' => true]);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.site_name', 'Live & Learn Events')
            ->assertJsonPath('data.registration_notice', 'Bring your ID.')
            ->assertJsonPath('data.maintenance_mode', true);
    }

    public function test_validation_rejects_blank_name_and_long_notice(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/admin/settings', ['site_name' => '   ', 'registration_notice' => str_repeat('a', 1001), 'maintenance_mode' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['site_name', 'registration_notice']);
    }

    public function test_only_master_admin_can_change_settings(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'event_admin']));
        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->putJson('/api/admin/settings', ['site_name' => 'X'])->assertForbidden();
    }

    public function test_maintenance_mode_pauses_new_registrations_and_reopens_after(): void
    {
        $event = $this->event();
        $this->save(['maintenance_mode' => true]);

        $this->getJson("/api/events/{$event->id}")->assertJsonPath('registration.open', false)->assertJsonPath('registration.reason', fn ($r) => str_contains($r, 'maintenance'));
        Sanctum::actingAs(User::factory()->create());
        $payload = ['event_id' => $event->id, 'full_name' => 'Sokha Leng', 'gender' => 'female', 'age' => 20, 'phone' => '097 123 456', 'email' => 'sokha@example.com', 'organization' => 'NGO'];
        $this->postJson('/api/registrations', $payload)->assertUnprocessable()->assertJsonValidationErrors('event_id');

        $this->save(['maintenance_mode' => false]);
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/registrations', $payload)->assertCreated();
    }
}
