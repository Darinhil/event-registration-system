<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FormField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DynamicFormTest extends TestCase
{
    use RefreshDatabase;

    private function eventWithForm(array $config = []): Event
    {
        $event = Event::create([
            'name' => 'Dynamic Form Expo',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(8),
            'location' => 'Phnom Penh',
            'capacity' => 50,
            'enabled_fields' => [],
            'form_config' => array_merge([
                'form_title' => 'Expo Signup',
                'form_description' => 'Tell us about yourself.',
                'steps' => [
                    ['id' => 's0', 'name' => 'About you'],
                    ['id' => 's1', 'name' => 'Preferences'],
                ],
                'settings' => ['allow_user_edit' => true, 'one_per_account' => true],
            ], $config),
            'status' => 'published',
        ]);

        FormField::create(['event_id' => $event->id, 'label' => 'Full Name', 'type' => 'text', 'required' => true, 'sort_order' => 0]);
        FormField::create(['event_id' => $event->id, 'label' => 'Work Email', 'type' => 'email', 'required' => true, 'sort_order' => 1]);
        FormField::create(['event_id' => $event->id, 'label' => 'Shirt Size', 'type' => 'select', 'required' => true, 'options' => ['S', 'M', 'L'], 'sort_order' => 2]);
        FormField::create(['event_id' => $event->id, 'label' => 'Sessions', 'type' => 'checkbox', 'required' => false, 'options' => ['AI', 'Web', 'Cloud'], 'settings' => ['multiple' => true], 'sort_order' => 3]);
        FormField::create(['event_id' => $event->id, 'label' => 'Newsletter', 'type' => 'checkbox', 'required' => false, 'settings' => ['checkbox_text' => 'Subscribe'], 'sort_order' => 4]);

        return $event;
    }

    public function test_form_endpoint_returns_full_builder_configuration(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = $this->eventWithForm();

        $response = $this->getJson("/api/events/{$event->id}/form");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame('Expo Signup', $data['form_title']);
        $this->assertSame('Tell us about yourself.', $data['form_description']);
        $this->assertCount(2, $data['steps']);
        $this->assertSame('About you', $data['steps'][0]['name']);
        $this->assertSame(['allow_user_edit' => true, 'one_per_account' => true], array_intersect_key($data['settings'], ['allow_user_edit' => true, 'one_per_account' => true]));
        $this->assertArrayHasKey('success_message', $data['settings']); // defaults are merged in
        $this->assertTrue($data['registration_open']);
        $this->assertCount(5, $data['fields']);

        $field = collect($data['fields'])->firstWhere('label', 'Shirt Size');
        $this->assertSame('select', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertSame(['S', 'M', 'L'], $field['options']);
        $this->assertSame(2, $field['sort_order']);
    }

    public function test_dynamic_field_validation_is_enforced(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = $this->eventWithForm();

        // Missing required answers + invalid select option + invalid email.
        $fields = $event->formFields()->get()->keyBy('label');
        $this->postJson('/api/registrations', [
            'event_id' => $event->id,
            'form_data' => [
                // full_name missing
                $fields['Work Email']->id => 'not-an-email',
                $fields['Shirt Size']->id => 'XXL',
                $fields['Sessions']->id => ['AI', 'Quantum'],
            ],
        ])->assertStatus(422);
    }

    public function test_valid_dynamic_submission_is_stored(): void
    {
        $user = User::factory()->create();
        $event = $this->eventWithForm();
        Sanctum::actingAs($user);

        $fields = $event->formFields()->get()->keyBy('label');
        $response = $this->postJson('/api/registrations', [
            'event_id' => $event->id,
            'form_data' => [
                $fields['Full Name']->id => 'Sokha Leng',
                $fields['Work Email']->id => 'sokha@example.com',
                $fields['Shirt Size']->id => 'M',
                $fields['Sessions']->id => ['AI', 'Web'],
                $fields['Newsletter']->id => true,
            ],
        ]);

        $response->assertCreated();

        $registration = $user->registrations()->first();
        $this->assertSame('M', $registration->form_data[$fields['Shirt Size']->id]);
        $this->assertSame(['AI', 'Web'], $registration->form_data[$fields['Sessions']->id]);
        $this->assertSame('Sokha Leng', $registration->full_name, 'Full Name answer should mirror onto the core column.');
    }

    public function test_one_per_account_setting_blocks_duplicate_registration(): void
    {
        $user = User::factory()->create();
        $event = $this->eventWithForm();
        Sanctum::actingAs($user);

        $fields = $event->formFields()->get()->keyBy('label');
        $payload = ['event_id' => $event->id, 'form_data' => [
            $fields['Full Name']->id => 'Sokha Leng',
            $fields['Work Email']->id => 'sokha@example.com',
            $fields['Shirt Size']->id => 'M',
            $fields['Newsletter']->id => true,
        ]];

        $this->postJson('/api/registrations', $payload)->assertCreated();
        $this->postJson('/api/registrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_id');
    }

    public function test_registration_window_is_enforced(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = $this->eventWithForm(['settings' => ['registration_end' => now()->subDay()->toIso8601String()]]);

        $fields = $event->formFields()->get()->keyBy('label');
        $this->postJson('/api/registrations', ['event_id' => $event->id, 'form_data' => [
            $fields['Full Name']->id => 'Sokha Leng',
            $fields['Work Email']->id => 'sokha@example.com',
            $fields['Shirt Size']->id => 'M',
        ]])->assertStatus(422);
    }
}
