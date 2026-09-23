<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dedicated endpoints for the visual Form Builder.
 *
 * The builder owns the whole registration form of an event:
 *  - GET    /admin/events/{event}/form-builder  -> full config (fields + settings + stats)
 *  - PUT    /admin/events/{event}/form-builder  -> save draft (fields + settings)
 *  - PATCH  /admin/events/{event}/form-builder/publish -> publish + registration link
 */
class FormBuilderController extends Controller
{
    /** Field types allowed by the builder, mapped to storage type strings. */
    public const TYPES = [
        'text', 'textarea', 'email', 'phone', 'number', 'date', 'time',
        'select', 'radio', 'checkbox', 'yesno', 'file', 'url', 'address', 'country', 'heading', 'autocomplete',
    ];

    public function show(Request $request, Event $event): JsonResponse
    {
        $fields = $event->formFields()->get(['id', 'label', 'description', 'placeholder', 'type', 'required', 'options', 'settings', 'sort_order'])
            ->map(fn ($field) => $this->presentField($field));

        return response()->json([
            'data' => [
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'status' => $event->status,
                    'capacity' => $event->capacity,
                    'registrations' => $event->registrations()->count(),
                ],
                'form_title' => $event->form_config['form_title'] ?? 'Event Registration Form',
                'form_description' => $event->form_config['form_description'] ?? 'Register for this event by completing the form below.',
                'config' => $event->form_config ?: $this->defaultConfig(),
                'fields' => $fields,
                'registration_link' => $event->status === 'published' ? $this->registrationLink($event) : null,
            ],
        ]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'form_title' => ['nullable', 'string', 'max:150'],
            'form_description' => ['nullable', 'string', 'max:1000'],
            'config' => ['nullable', 'array'],
            'fields' => ['present', 'array'],
            'fields.*.label' => ['required', 'string', 'max:150'],
            'fields.*.description' => ['nullable', 'string', 'max:300'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:150'],
            'fields.*.type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'fields.*.required' => ['boolean'],
            'fields.*.options' => ['nullable', 'array', 'max:100'],
            'fields.*.options.*' => ['string', 'max:150'],
            'fields.*.settings' => ['nullable', 'array'],
        ]);

        $this->assertSafeEdit($event, $data['fields'] ?? []);

        DB::transaction(function () use ($event, $data): void {
            $config = $event->form_config ?: $this->defaultConfig();
            $config['form_title'] = $data['form_title'] ?? $config['form_title'] ?? 'Event Registration Form';
            $config['form_description'] = $data['form_description'] ?? $config['form_description'] ?? '';
            $config['settings'] = array_merge($config['settings'] ?? [], $data['config']['settings'] ?? []);
            $config['updated_at'] = now()->toIso8601String();
            $event->update(['form_config' => $config]);

            $event->formFields()->delete();
            foreach ($data['fields'] as $index => $field) {
                $event->formFields()->create([
                    'label' => $field['label'],
                    'description' => $field['description'] ?? null,
                    'placeholder' => $field['placeholder'] ?? null,
                    'type' => $field['type'],
                    'required' => (bool) ($field['required'] ?? false),
                    'options' => $field['options'] ?? null,
                    'settings' => $field['settings'] ?? null,
                    'sort_order' => $index,
                ]);
            }
        });

        return $this->show($request, $event);
    }

    public function publish(Request $request, Event $event): JsonResponse
    {
        if (! $event->formFields()->exists()) {
            abort(422, 'Add at least one field before publishing.');
        }

        $event->update(['status' => 'published']);

        return response()->json([
            'data' => [
                'status' => 'published',
                'registration_link' => $this->registrationLink($event),
            ],
        ]);
    }

    /** Warn-worthy edit guard: fields changed while registrations exist -> client shows confirmation first. */
    private function assertSafeEdit(Event $event, array $incoming): void
    {
        $registrations = $event->registrations()->count();
        if ($registrations === 0) {
            return;
        }

        $current = $event->formFields()->get(['label', 'type', 'required'])->map(
            fn ($f) => [$f->label, $f->type, (int) $f->required]
        )->toJson();

        $next = collect($incoming)->map(
            fn ($f) => [$f['label'], $f['type'], (int) (bool) ($f['required'] ?? false)]
        )->toJson();

        // Silently allow identical saves; the frontend is responsible for the
        // "form has registrations" confirmation before structural edits.
        if ($current === $next) {
            return;
        }

        // Still allow it — but flag it in the response so the UI can inform the admin.
        $event->form_config = array_merge($event->form_config ?: [], ['last_structural_edit_with_registrations' => now()->toIso8601String()]);
    }

    private function registrationLink(Event $event): string
    {
        // Prefer an explicitly configured frontend origin, then the request's
        // Origin header (set by the SPA), and finally the backend URL.
        $origin = config('app.frontend_url')
            ?: request()->header('origin')
            ?: rtrim(config('app.url'), '/');

        return rtrim($origin, '/')."/events/{$event->id}/register";
    }

    private function defaultConfig(): array
    {
        return [
            'form_title' => 'Event Registration Form',
            'form_description' => 'Register for this event by completing the form below.',
            'settings' => [
                'registration_start' => null,
                'registration_end' => null,
                'allow_user_edit' => true,
                'max_participants' => null,
                'multiple_registrations' => false,
                'one_per_account' => true,
                'success_message' => 'Thank you! Your registration has been received.',
                'redirect_url' => null,
                'confirmation_email' => true,
                'show_privacy_policy' => false,
                'require_agreement' => false,
                'terms_url' => null,
            ],
        ];
    }

    private function presentField($field): array
    {
        return [
            'id' => $field->id,
            'label' => $field->label,
            'description' => $field->description,
            'placeholder' => $field->placeholder,
            'type' => $field->type,
            'required' => (bool) $field->required,
            'options' => $field->options ?? [],
            'settings' => $field->settings ?? [],
            'sort_order' => $field->sort_order,
        ];
    }
}
