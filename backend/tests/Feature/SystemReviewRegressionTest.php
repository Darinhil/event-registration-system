<?php

namespace Tests\Feature;

use App\Models\CheckIn;
use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Bugs found in the end-to-end system review. Each test failed before its fix. */
class SystemReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function event(array $attributes = []): Event
    {
        return Event::create([
            'name' => 'Community Forum', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(8),
            'location' => 'Phnom Penh', 'capacity' => 50, 'enabled_fields' => [], 'status' => 'published', ...$attributes,
        ]);
    }

    private function registration(User $user, Event $event, array $attributes = []): Registration
    {
        return Registration::create([
            'user_id' => $user->id, 'event_id' => $event->id, 'event_name' => $event->name, 'event_date' => $event->starts_at,
            'event_location' => $event->location, 'qr_token' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'confirmed', ...$attributes,
        ]);
    }

    public function test_check_in_lookup_without_a_credential_is_a_validation_error(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/check-ins/lookup')->assertUnprocessable()->assertJsonValidationErrors('credential');
    }

    public function test_a_second_scan_that_loses_the_race_reports_already_checked_in(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $registration = $this->registration(User::factory()->create(), $this->event());

        // Another desk inserts the check-in after this request's exists() check has passed.
        CheckIn::creating(function (CheckIn $checkIn) use ($registration, $admin): void {
            if ($checkIn->registration_id === $registration->id && ! CheckIn::where('registration_id', $registration->id)->exists()) {
                \Illuminate\Support\Facades\DB::table('check_ins')->insert(['registration_id' => $registration->id, 'checked_in_by' => $admin->id, 'checked_in_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->postJson('/api/check-ins', ['credential' => $registration->qr_token])->assertUnprocessable()->assertJsonValidationErrors('credential');
    }

    public function test_voucher_can_be_saved_when_optional_fields_are_omitted(): void
    {
        Sanctum::actingAs($this->admin());

        $this->postJson('/api/admin/finance-vouchers', ['number' => 'V-1', 'lines' => [['description' => 'Fuel']]])->assertCreated()->assertJsonPath('data.payee_name', null);
    }

    public function test_incomplete_draft_cannot_be_published_from_the_form_builder(): void
    {
        Sanctum::actingAs($this->admin());
        $event = Event::create(['name' => 'Draft only', 'status' => 'draft', 'enabled_fields' => []]);
        FormField::create(['event_id' => $event->id, 'label' => 'Name', 'type' => 'text', 'sort_order' => 0]);

        $this->patchJson("/api/admin/events/{$event->id}/form-builder/publish")->assertUnprocessable();
        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_closed_or_cancelled_events_cannot_be_republished_from_the_form_builder(): void
    {
        Sanctum::actingAs($this->admin());
        foreach (['closed', 'cancelled'] as $status) {
            $event = $this->event(['status' => $status]);
            FormField::create(['event_id' => $event->id, 'label' => 'Name', 'type' => 'text', 'sort_order' => 0]);

            $this->patchJson("/api/admin/events/{$event->id}/form-builder/publish")->assertUnprocessable();
            $this->assertSame($status, $event->fresh()->status);
        }
    }

    public function test_events_with_khmer_names_still_get_readable_registration_codes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $first = $this->event(['name' => 'សន្និបាត']);
        $second = $this->event(['name' => 'វេទិកា']);

        $this->register($first)->assertCreated()->assertJsonPath('data.registration_code', "EVENT-{$first->id}-001");
        $this->register($second)->assertCreated()->assertJsonPath('data.registration_code', "EVENT-{$second->id}-001");
    }

    private function register(Event $event)
    {
        $field = FormField::firstOrCreate(['event_id' => $event->id, 'label' => 'Notes'], ['type' => 'text', 'sort_order' => 0]);

        return $this->postJson('/api/registrations', ['event_id' => $event->id, 'form_data' => [$field->id => 'Hello']]);
    }

    public function test_registration_codes_do_not_reuse_a_gap_or_scan_every_earlier_code(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $event = $this->event(['name' => 'Forum', 'form_config' => ['settings' => ['one_per_account' => false]]]);

        foreach (range(1, 3) as $n) {
            $this->register($event)->assertCreated()->assertJsonPath('data.registration_code', 'FORUM-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT));
        }
        Registration::where('registration_code', 'FORUM-002')->delete();
        $this->register($event)->assertCreated()->assertJsonPath('data.registration_code', 'FORUM-004');

        // Two events that share a name share the code sequence, because the unique index is global.
        $twin = $this->event(['name' => 'Forum']);
        $this->register($twin)->assertCreated()->assertJsonPath('data.registration_code', 'FORUM-005');
    }

    public function test_attendance_check_in_filter_only_looks_at_the_selected_event(): void
    {
        Sanctum::actingAs($this->admin());
        [$selected, $other] = [$this->event(), $this->event(['name' => 'Other'])];
        $person = User::factory()->create();
        $this->registration($person, $selected);
        CheckIn::create(['registration_id' => $this->registration($person, $other)->id, 'checked_in_at' => now()]);

        $this->getJson("/api/admin/users?event_id={$selected->id}&check_in=in")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/admin/users?event_id={$selected->id}&check_in=out")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_attendance_does_not_expose_personnel_records_to_event_organisers(): void
    {
        $owner = User::factory()->create(['role' => 'event_admin']);
        Sanctum::actingAs($owner);
        $event = $this->event(['created_by' => $owner->id]);
        $person = User::factory()->create(['personal_information' => ['id_passport_number' => 'N123456'], 'family_information' => ['beneficiaries' => [['full_name' => 'Secret']]]]);
        $this->registration($person, $event);

        $response = $this->getJson("/api/admin/users?event_id={$event->id}")->assertOk();
        $response->assertJsonPath('data.0.id', $person->id);
        $response->assertJsonMissingPath('data.0.personal_information');
        $response->assertJsonMissingPath('data.0.family_information');
        $this->assertStringNotContainsString('N123456', $response->getContent());
    }

    public function test_attendance_includes_staff_who_registered_so_totals_match_the_reports(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);
        $event = $this->event();
        $this->registration($admin, $event);
        $this->registration(User::factory()->create(), $event);

        $this->getJson("/api/admin/users?event_id={$event->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/reports')->assertOk()->assertJsonPath('data.0.registrations', 2);
    }

    /** @return array{Event, FormField, FormField, User, Registration} */
    private function eventWithFileAnswer(): array
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $event = $this->event(['form_config' => ['settings' => ['allow_user_edit' => true]]]);
        $name = FormField::create(['event_id' => $event->id, 'label' => 'Full Name', 'type' => 'text', 'required' => true, 'sort_order' => 0]);
        $file = FormField::create(['event_id' => $event->id, 'label' => 'CV', 'type' => 'file', 'required' => true, 'sort_order' => 1, 'settings' => ['allowed_types' => ['pdf']]]);
        $path = UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')->store('form-uploads', 'public');
        $registration = $this->registration($user, $event, ['form_data' => [$name->id => 'Sokha', $file->id => $path], 'registration_code' => 'CODE-1', 'full_name' => 'Sokha']);

        return [$event, $name, $file, $user, $registration];
    }

    public function test_editing_a_registration_keeps_the_uploaded_file_when_no_new_one_is_sent(): void
    {
        [$event, $name, $file, $user, $registration] = $this->eventWithFileAnswer();
        $path = $registration->form_data[$file->id];
        Sanctum::actingAs($user);

        $this->putJson("/api/registrations/{$registration->id}", ['event_id' => $event->id, 'form_data' => [$name->id => 'Sokha Leng', $file->id => '']])->assertOk();

        $saved = $registration->fresh();
        $this->assertSame('Sokha Leng', $saved->form_data[$name->id]);
        $this->assertSame($path, $saved->form_data[$file->id]);
    }

    public function test_editing_a_registration_can_replace_the_uploaded_file(): void
    {
        [$event, $name, $file, $user, $registration] = $this->eventWithFileAnswer();
        $old = $registration->form_data[$file->id];
        Sanctum::actingAs($user);

        $this->post("/api/registrations/{$registration->id}", ['_method' => 'PUT', 'event_id' => $event->id, 'form_data' => [$name->id => 'Sokha', $file->id => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])->assertOk();

        $this->assertNotSame($old, $registration->fresh()->form_data[$file->id]);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_registrations_cannot_be_edited_after_the_event_ends(): void
    {
        $user = User::factory()->create();
        $event = $this->event(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'form_config' => ['settings' => ['allow_user_edit' => true]]]);
        $field = FormField::create(['event_id' => $event->id, 'label' => 'Notes', 'type' => 'text', 'sort_order' => 0]);
        $registration = $this->registration($user, $event);
        Sanctum::actingAs($user);

        $this->getJson("/api/registrations/{$registration->id}")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->putJson("/api/registrations/{$registration->id}", ['event_id' => $event->id, 'form_data' => [$field->id => 'Late']])->assertForbidden();
    }

    public function test_profile_photo_url_is_a_single_valid_url(): void
    {
        Storage::fake('public', ['url' => 'http://localhost/storage']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $png = UploadedFile::fake()->createWithContent('me.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $url = $this->post('/api/me/profile', ['profile_photo' => $png], ['Accept' => 'application/json'])->assertOk()->json('data.profile_photo');

        $this->assertNotFalse(filter_var($url, FILTER_VALIDATE_URL), "Not a valid URL: {$url}");
        $this->assertSame(1, substr_count($url, '://'));
    }

    public function test_changing_a_password_signs_out_other_devices_only(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $other = $user->createToken('phone')->plainTextToken;
        $current = $user->createToken('laptop')->plainTextToken;

        $this->withToken($current)->patchJson('/api/me/password', ['current_password' => 'old-password', 'new_password' => 'new-password', 'new_password_confirmation' => 'new-password'])->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('laptop', $user->tokens()->first()->name);
        $this->assertNotNull($other);
    }

    public function test_admin_password_reset_and_account_deletion_revoke_sessions(): void
    {
        Sanctum::actingAs($this->admin());
        $member = User::factory()->create(['role' => 'manager']);
        $member->createToken('web');

        $this->putJson("/api/admin/admin-accounts/{$member->id}", ['password' => 'brand-new-pass'])->assertOk();
        $this->assertSame(0, $member->tokens()->count());

        $member->createToken('web');
        $this->deleteJson("/api/admin/admin-accounts/{$member->id}")->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $member->id, 'tokenable_type' => User::class]);
    }

    public function test_event_lists_link_to_banners_instead_of_embedding_them(): void
    {
        $image = 'data:image/png;base64,'.base64_encode(str_repeat('x', 200000));
        $draft = $this->event(['status' => 'draft', 'branding' => ['image' => $image]]);
        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/admin/events')->assertOk();
        $this->assertLessThan(10000, strlen($response->getContent()));
        $url = $response->json('0.branding.image');
        $this->assertStringContainsString("/api/events/{$draft->id}/banner", $url);

        // The list is only shown to staff, and an <img> can't send a token, so the link itself is the permission.
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->getJson("/api/events/{$draft->id}/banner")->assertNotFound();
    }

    public function test_full_form_of_an_event_still_returns_the_banner_for_editing(): void
    {
        $image = 'data:image/png;base64,'.base64_encode('png');
        $event = $this->event(['branding' => ['image' => $image]]);

        $this->getJson("/api/events/{$event->id}")->assertOk()->assertJsonPath('data.branding.image', $image);
    }

    public function test_spots_left_uses_the_same_limit_that_closes_registration(): void
    {
        $event = $this->event(['capacity' => 50, 'form_config' => ['settings' => ['max_participants' => 1]]]);
        $this->registration(User::factory()->create(), $event);

        $this->getJson("/api/events/{$event->id}")->assertOk()->assertJsonPath('remaining', 0)->assertJsonPath('registration.open', false);
    }

    public function test_the_registration_deadline_ends_at_midnight_in_the_organisation_time_zone(): void
    {
        config(['app.business_timezone' => 'Asia/Phnom_Penh']);
        $event = $this->event(['starts_at' => '2030-04-01 02:00:00', 'ends_at' => '2030-04-01 10:00:00', 'form_config' => ['settings' => ['registration_end' => '2030-03-10']]]);

        // 23:30 on the 10th in Phnom Penh is 16:30 UTC: still open.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2030-03-10 16:30:00', 'UTC'));
        $this->getJson("/api/events/{$event->id}")->assertJsonPath('registration.open', true);

        // 00:30 on the 11th in Phnom Penh is 17:30 UTC on the 10th: closed, though UTC is still on the 10th.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2030-03-10 17:30:00', 'UTC'));
        $this->getJson("/api/events/{$event->id}")->assertJsonPath('registration.open', false);
    }

    public function test_unlimited_leave_types_do_not_block_timesheet_submission(): void
    {
        $officer = User::factory()->create(['role' => 'officer_1']);
        $manager = User::factory()->create(['role' => 'manager']);
        Sanctum::actingAs($officer);
        $this->travelTo(\Illuminate\Support\Carbon::create(2026, 2, 10));
        $entries = [];
        foreach (range(1, 28) as $day) {
            if (! \Illuminate\Support\Carbon::create(2026, 2, $day)->isWeekend()) $entries[(string) $day] = ['hours' => 6.5];
        }
        $entries['2'] = ['leave_type' => 'unpaid', 'leave_part' => 'full'];

        $this->postJson('/api/me/timesheets/2026/2/submit', ['entries' => $entries, 'approver_id' => $manager->id, 'certify' => true])->assertOk()->assertJsonPath('data.status', 'submitted');
    }

    public function test_the_seeder_refuses_a_default_admin_password_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);

        (new \Database\Seeders\AdminSeeder)->run();
    }
}
