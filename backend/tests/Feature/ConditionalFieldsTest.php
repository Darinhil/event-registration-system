<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConditionalFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;
    private FormField $name;
    private FormField $diet;
    private FormField $dietDetails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->event = Event::create([
            'name' => 'Conditional Expo', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(8),
            'location' => 'Phnom Penh', 'capacity' => 50, 'enabled_fields' => [], 'status' => 'published',
            'form_config' => ['settings' => ['allow_user_edit' => true, 'one_per_account' => true]],
        ]);
        $this->name = FormField::create(['event_id' => $this->event->id, 'label' => 'Full Name', 'type' => 'text', 'required' => true, 'sort_order' => 0]);
        $this->diet = FormField::create(['event_id' => $this->event->id, 'label' => 'Any dietary needs?', 'type' => 'yesno', 'required' => true, 'sort_order' => 1]);
        $this->dietDetails = FormField::create(['event_id' => $this->event->id, 'label' => 'Tell us more', 'type' => 'text', 'required' => true, 'sort_order' => 2,
            'settings' => ['show_if' => ['field' => $this->diet->id, 'equals' => 'Yes']]]);
    }

    private function register(array $answers)
    {
        Sanctum::actingAs(User::factory()->create());
        return $this->postJson('/api/registrations', ['event_id' => $this->event->id, 'form_data' => $answers]);
    }

    public function test_a_hidden_required_question_does_not_block_registration(): void
    {
        $this->register([$this->name->id => 'Sokha Leng', $this->diet->id => 'No'])->assertCreated();
    }

    public function test_a_shown_required_question_must_be_answered(): void
    {
        $this->register([$this->name->id => 'Sokha Leng', $this->diet->id => 'Yes'])
            ->assertStatus(422)->assertJsonValidationErrors("form_data.{$this->dietDetails->id}");

        $this->register([$this->name->id => 'Dara Chan', $this->diet->id => 'Yes', $this->dietDetails->id => 'Vegetarian'])->assertCreated();
        $this->assertSame('Vegetarian', Registration::latest('id')->first()->form_data[$this->dietDetails->id] ?? null);
    }

    public function test_answers_to_hidden_questions_are_not_stored(): void
    {
        $this->register([$this->name->id => 'Sokha Leng', $this->diet->id => 'No', $this->dietDetails->id => 'Left over from an earlier answer'])->assertCreated();

        $formData = Registration::latest('id')->first()->form_data;
        $keys = array_map('strval', array_keys($formData));
        $this->assertContains((string) $this->diet->id, $keys);
        $this->assertNotContains((string) $this->dietDetails->id, $keys);
    }

    public function test_saving_new_fields_points_conditions_at_their_saved_ids(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/admin/events/{$this->event->id}/form-builder", ['fields' => [
            ['id' => 'fnewsource', 'label' => 'Bringing a guest?', 'type' => 'radio', 'required' => true, 'options' => ['Yes', 'No']],
            ['id' => 'fnewtarget', 'label' => 'Guest name', 'type' => 'text', 'required' => true, 'settings' => ['show_if' => ['field' => 'fnewsource', 'equals' => 'Yes']]],
        ]])->assertOk();

        $source = FormField::where('label', 'Bringing a guest?')->firstOrFail();
        $target = FormField::where('label', 'Guest name')->firstOrFail();
        $this->assertSame((string) $source->id, (string) $target->settings['show_if']['field']);
        $this->assertSame('Yes', $target->settings['show_if']['equals']);
    }
}
