<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Storage;
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

    public function test_dynamic_file_fields_are_uploaded_and_saved(): void
    {
        Storage::fake('public');
        $event = $this->event();
        $field = $event->formFields()->create(['label' => 'Supporting document', 'type' => 'file', 'required' => true, 'settings' => ['allowed_types' => ['pdf'], 'max_size_mb' => 5]]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->post('/api/registrations', [
            ...$this->payload($event, 'upload@example.com'),
            'form_data' => [$field->id => UploadedFile::fake()->create('supporting-document.pdf', 32, 'application/pdf')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $formData = $response->json('data.form_data');
        $this->assertCount(1, $formData);
        $storedFormData = Registration::query()->latest('id')->firstOrFail()->form_data;
        $this->assertArrayHasKey($field->id, $storedFormData);
        $path = $storedFormData[$field->id];
        $this->assertIsString($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_dynamic_url_fields_accept_valid_urls_and_reject_invalid_values(): void
    {
        $event = $this->event();
        $field = $event->formFields()->create(['label' => 'Website', 'type' => 'url', 'required' => true]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/registrations', [
            ...$this->payload($event, 'valid-url@example.com'),
            'form_data' => [$field->id => 'https://example.com/profile'],
        ])->assertCreated();

        $this->postJson('/api/registrations', [
            ...$this->payload($event, 'invalid-url@example.com'),
            'form_data' => [$field->id => 'not a link'],
        ])->assertStatus(422)->assertJsonValidationErrors("form_data.{$field->id}");
    }

    public function test_dynamic_yes_no_and_checkbox_fields_are_saved(): void
    {
        $event = $this->event();
        $yesNo = $event->formFields()->create(['label' => 'Do you know this event?', 'type' => 'yesno', 'required' => true]);
        $checkbox = $event->formFields()->create(['label' => 'Which sessions?', 'type' => 'checkbox', 'required' => true, 'options' => ['Morning', 'Afternoon'], 'settings' => ['multiple' => true]]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/registrations', [
            ...$this->payload($event, 'choices@example.com'),
            'form_data' => [$yesNo->id => 'Yes', $checkbox->id => ['Morning', 'Afternoon']],
        ])->assertCreated();

        $stored = \App\Models\Registration::query()->latest('id')->firstOrFail()->form_data;
        $this->assertSame('Yes', $stored[$yesNo->id]);
        $this->assertSame(['Morning', 'Afternoon'], $stored[$checkbox->id]);
    }

    public function test_requested_registration_profile_fields_accept_and_map_answers(): void
    {
        $event = $this->event();
        $name = $event->formFields()->create(['label' => 'Name', 'type' => 'text', 'required' => true]);
        $email = $event->formFields()->create(['label' => 'Email', 'type' => 'email', 'required' => true]);
        $gender = $event->formFields()->create(['label' => 'Gender', 'type' => 'radio', 'required' => true, 'options' => ['M: Male', 'F: Female', 'P: Prefer not to say']]);
        $age = $event->formFields()->create(['label' => 'Age', 'type' => 'radio', 'required' => true, 'options' => ['K: Under 18', 'Y: 18–29', 'A: 30–60', 'E: Above 60']]);
        $disability = $event->formFields()->create(['label' => 'Disability', 'type' => 'checkbox', 'options' => ['C: Difficulty seeing', 'H: Difficulty hearing'], 'settings' => ['multiple' => true]]);
        $institution = $event->formFields()->create(['label' => 'Institution', 'type' => 'text', 'required' => true]);
        $telephone = $event->formFields()->create(['label' => 'TEL', 'type' => 'phone', 'required' => true]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/registrations', [
            'event_id' => $event->id,
            'form_data' => [
                $name->id => 'Sokha Leng',
                $email->id => 'profile@example.com',
                $gender->id => 'M: Male',
                $age->id => 'Y: 18–29',
                $disability->id => ['C: Difficulty seeing', 'H: Difficulty hearing'],
                $institution->id => 'Community Institute',
                $telephone->id => '097 123 456',
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.full_name', 'Sokha Leng')
            ->assertJsonPath('data.email', 'profile@example.com')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.phone', '097 123 456')
            ->assertJsonPath('data.disability_type', ['C', 'H']);
        $registration = Registration::query()->latest('id')->firstOrFail();
        $this->assertSame('Y: 18–29', $registration->age_group);
        $this->assertSame(['C: Difficulty seeing', 'H: Difficulty hearing'], $registration->form_data[$disability->id]);
    }

    public function test_non_binary_gender_option_maps_to_other_profile_value(): void
    {
        $event = $this->event();
        $gender = $event->formFields()->create(['label' => 'Gender', 'type' => 'radio', 'required' => true, 'options' => ['M: Male', 'F: Female', 'P: Prefer not to say', 'N: Non-binary']]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/registrations', [
            ...$this->payload($event, 'nonbinary@example.com'),
            'form_data' => [$gender->id => 'N: Non-binary'],
        ])->assertCreated()->assertJsonPath('data.gender', 'other');
    }
}