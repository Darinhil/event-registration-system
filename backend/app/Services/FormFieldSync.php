<?php

namespace App\Services;

use App\Models\Event;
use App\Models\FormField;

/**
 * Saves an event's registration form without breaking existing answers.
 *
 * Registration answers (registrations.form_data) are keyed by form field id.
 * Deleting and recreating every field on save gave each field a new id, so
 * past answers lost their question labels in the registrant list and exports.
 * Fields are now updated in place; removed fields keep their label in
 * form_config.archived_fields so historical answers stay readable.
 */
class FormFieldSync
{
    public function sync(Event $event, array $incoming): void
    {
        $existing = $event->formFields()->get()->keyBy('id');
        $unmatched = $existing->keys()->flip();
        $keep = [];

        foreach (array_values($incoming) as $index => $field) {
            $attributes = [
                'label' => $field['label'],
                'description' => $field['description'] ?? null,
                'placeholder' => $field['placeholder'] ?? null,
                'type' => $field['type'] ?? 'text',
                'required' => (bool) ($field['required'] ?? false),
                'options' => $field['options'] ?? null,
                'settings' => $field['settings'] ?? null,
                'sort_order' => $index,
            ];

            $match = $this->match($existing, $unmatched, $field);
            if ($match) {
                $match->update($attributes);
                $unmatched->forget($match->id);
                $keep[] = $match->id;
            } else {
                $keep[] = $event->formFields()->create($attributes)->id;
            }
        }

        $removed = $existing->except($keep);
        if ($removed->isEmpty()) return;

        // Preserve labels of removed fields that already hold answers.
        if ($event->registrations()->exists()) {
            $config = $event->form_config ?: [];
            $archived = $config['archived_fields'] ?? [];
            foreach ($removed as $field) $archived[(string) $field->id] = $field->label;
            $config['archived_fields'] = $archived;
            $event->forceFill(['form_config' => $config])->save();
        }
        FormField::whereIn('id', $removed->keys())->delete();
    }

    /**
     * Match by id when the client sends one of this event's field ids (the form
     * builder does). The event wizard keeps its own local ids, so fall back to an
     * unused field with the same label and type.
     */
    private function match($existing, $unmatched, array $field): ?FormField
    {
        $id = $field['id'] ?? null;
        if (is_numeric($id) && $unmatched->has((int) $id)) {
            $candidate = $existing->get((int) $id);
            if ($candidate && ($candidate->label === $field['label'] || $candidate->type === ($field['type'] ?? 'text'))) return $candidate;
        }
        foreach ($unmatched->keys() as $candidateId) {
            $candidate = $existing->get($candidateId);
            if ($candidate->label === $field['label'] && $candidate->type === ($field['type'] ?? 'text')) return $candidate;
        }
        return null;
    }
}
