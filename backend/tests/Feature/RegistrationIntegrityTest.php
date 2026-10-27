<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;
    /** @var array<string, FormField> */
    private array $fields = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::create([
            'name' => 'Integrity Forum', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(8),
            'location' => 'Phnom Penh', 'capacity' => 3, 'enabled_fields' => [], 'status' => 'published',
            'form_config' => ['settings' => ['one_per_account' => true]],
        ]);
        $make = fn (string $key, array $attrs, int $order) => $this->fields[$key] = FormField::create(['event_id' => $this->event->id, 'sort_order' => $order, 'required' => false, ...$attrs]);
        $make('name', ['label' => 'Full Name', 'type' => 'text', 'required' => true], 0);
        $make('email', ['label' => 'Email', 'type' => 'email', 'required' => true], 1);
        $make('phone', ['label' => 'Phone', 'type' => 'phone'], 2);
        $make('track', ['label' => 'Track', 'type' => 'radio', 'options' => ['Policy', 'Research']], 3);
        $make('topics', ['label' => 'Topics', 'type' => 'select', 'options' => ['AI', 'Water', 'Energy'], 'settings' => ['multiple' => true]], 4);
        $make('consent', ['label' => 'Consent', 'type' => 'checkbox', 'required' => true, 'settings' => ['checkbox_text' => 'I agree']], 5);
        $make('notes', ['label' => 'Notes', 'type' => 'textarea', 'settings' => ['max_length' => 20]], 6);
    }

    private function answers(array $overrides = []): array
    {
        return array_replace([
            $this->fields['name']->id => 'Sokha Leng',
            $this->fields['email']->id => 'sokha@example.com',
            $this->fields['phone']->id => '+855 12 345 678',
            $this->fields['track']->id => 'Policy',
            $this->fields['topics']->id => ['AI', 'Water'],
            $this->fields['consent']->id => true,
            $this->fields['notes']->id => 'Vegetarian',
        ], $overrides);
    }

    private function register(array $answers, ?User $user = null)
    {
        Sanctum::actingAs($user ?? User::factory()->create());
        return $this->postJson('/api/registrations', ['event_id' => $this->event->id, 'form_data' => $answers]);
    }

    public function test_valid_registration_with_multi_select_is_saved(): void
    {
        $this->register($this->answers())->assertCreated()->assertJsonPath('data.email', 'sokha@example.com');
        $this->assertSame(['AI', 'Water'], Registration::firstOrFail()->form_data[$this->fields['topics']->id]);
    }

    public function test_server_rejects_invalid_or_manipulated_values(): void
    {
        $this->register($this->answers([$this->fields['email']->id => 'not-an-email']))->assertStatus(422)->assertJsonValidationErrors("form_data.{$this->fields['email']->id}");
        $this->register($this->answers([$this->fields['phone']->id => 'call me']))->assertStatus(422)->assertJsonValidationErrors("form_data.{$this->fields['phone']->id}");
        $this->register($this->answers([$this->fields['track']->id => 'Hacked option']))->assertStatus(422)->assertJsonValidationErrors("form_data.{$this->fields['track']->id}");
        $this->register($this->answers([$this->fields['topics']->id => ['AI', 'Not offered']]))->assertStatus(422)->assertJsonValidationErrors("form_data.{$this->fields['topics']->id}.1");
        $this->register($this->answers([$this->fields['notes']->id => str_repeat('x', 21)]))->assertStatus(422)->assertJsonValidationErrors("form_data.{$this->fields['notes']->id}");
        $this->assertSame(0, Registration::count());
    }

    public function test_required_consent_checkbox_must_be_ticked(): void
    {
        $this->register($this->answers([$this->fields['consent']->id => false]))->assertStatus(422)->assertJsonValidationErrors("form_data.{$this->fields['consent']->id}");
    }

    public function test_answers_for_unknown_fields_are_discarded(): void
    {
        $this->register($this->answers() + [999999 => 'injected', 'is_admin' => '1'])->assertCreated();
        $stored = Registration::firstOrFail()->form_data;
        $this->assertArrayNotHasKey('999999', $stored);
        $this->assertArrayNotHasKey('is_admin', $stored);
    }

    public function test_file_fields_reject_disallowed_types_on_the_server(): void
    {
        Storage::fake('public');
        $upload = FormField::create(['event_id' => $this->event->id, 'label' => 'CV', 'type' => 'file', 'sort_order' => 7, 'settings' => ['allowed_types' => ['pdf'], 'max_size_mb' => 1]]);
        $this->register($this->answers([$upload->id => UploadedFile::fake()->create('virus.exe', 10)]))->assertStatus(422)->assertJsonValidationErrors("form_data.{$upload->id}");
        $this->register($this->answers([$upload->id => UploadedFile::fake()->create('cv.pdf', 2048, 'application/pdf')]))->assertStatus(422)->assertJsonValidationErrors("form_data.{$upload->id}");
    }

    public function test_duplicate_registration_and_full_capacity_are_refused(): void
    {
        $user = User::factory()->create();
        $this->register($this->answers(), $user)->assertCreated();
        $this->register($this->answers([$this->fields['email']->id => 'other@example.com']), $user)->assertStatus(422)->assertJsonValidationErrors('event_id');
        $this->register($this->answers())->assertStatus(422)->assertJsonValidationErrors('email');

        $this->register($this->answers([$this->fields['email']->id => 'b@example.com']))->assertCreated();
        $this->register($this->answers([$this->fields['email']->id => 'c@example.com']))->assertCreated();
        $this->register($this->answers([$this->fields['email']->id => 'd@example.com']))->assertStatus(422)->assertJsonValidationErrors(['event_id' => 'full']);
        $this->assertSame(3, Registration::count());
        $this->getJson("/api/events/{$this->event->id}")->assertJsonPath('registration.open', false)->assertJsonPath('registration.reason', 'This event is full.');
    }

    public function test_registration_after_the_event_ended_is_refused(): void
    {
        $this->event->update(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $this->register($this->answers())->assertStatus(422)->assertJsonValidationErrors(['event_id' => 'ended']);
    }

    public function test_public_endpoints_report_why_registration_is_closed(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/events/{$this->event->id}")->assertJsonPath('registration.open', true)->assertJsonPath('registration.reason', null);
        $this->event->update(['form_config' => ['settings' => ['registration_start' => now()->addDays(3)->toDateString()]]]);
        $this->getJson("/api/events/{$this->event->id}/form")->assertJsonPath('data.registration_open', false)->assertJsonPath('data.registration_closed_reason', 'Registration for this event has not opened yet.');
    }

    public function test_editing_the_form_keeps_existing_answers_attached(): void
    {
        $this->register($this->answers())->assertCreated();
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        // Rename one field, remove another, add a new one — as the form builder sends it.
        $fields = collect($this->fields)->except('notes')->map(fn (FormField $field) => ['id' => $field->id, 'label' => $field->label, 'type' => $field->type, 'required' => $field->required, 'options' => $field->options, 'settings' => $field->settings])->values()->all();
        $fields[3]['label'] = 'Preferred track';
        $fields[] = ['id' => 'f123new', 'label' => 'Dietary needs', 'type' => 'text', 'required' => false];
        $this->putJson("/api/admin/events/{$this->event->id}/form-builder", ['fields' => $fields])->assertOk();

        $this->assertSame($this->fields['track']->id, FormField::where('label', 'Preferred track')->value('id'), 'Renamed field keeps its id');
        $answers = $this->getJson("/api/admin/events/{$this->event->id}/report-attendees")->assertOk()->json('data.attendees.0.form_answers');
        $this->assertSame('Policy', $answers['Preferred track']);
        $this->assertSame('Sokha Leng', $answers['Full Name']);
        $this->assertSame('Vegetarian', $answers['Notes'], 'Removed field keeps its label for past answers');
    }

    public function test_event_wizard_save_without_ids_keeps_matching_fields(): void
    {
        $this->register($this->answers())->assertCreated();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $before = FormField::where('event_id', $this->event->id)->orderBy('sort_order')->pluck('id')->all();
        $formFields = collect($this->fields)->map(fn (FormField $field) => ['id' => 9000 + $field->sort_order, 'label' => $field->label, 'type' => $field->type, 'required' => $field->required, 'options' => $field->options ?? [], 'settings' => $field->settings ?? []])->values()->all();

        $this->putJson("/api/admin/events/{$this->event->id}", ['name' => 'Integrity Forum', 'form_fields' => $formFields])->assertOk();

        $this->assertSame($before, FormField::where('event_id', $this->event->id)->orderBy('sort_order')->pluck('id')->all());
        $this->assertSame('Sokha Leng', $this->getJson("/api/admin/events/{$this->event->id}/report-attendees")->json('data.attendees.0.form_answers.Full Name'));
    }
}
