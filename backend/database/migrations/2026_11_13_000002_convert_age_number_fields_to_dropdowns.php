<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OPTIONS = [
        'K: ក្រោម 18 ឆ្នាំ/Under 18',
        'Y: 18–29',
        'A: 30–60',
        'E: លើស 60 ឆ្នាំ/Above 60',
    ];

    public function up(): void
    {
        $fields = DB::table('form_fields')
            ->where('type', 'number')
            ->where('label', 'like', '%Age%')
            ->get(['id', 'settings']);

        foreach ($fields as $field) {
            $settings = json_decode($field->settings ?: '{}', true) ?: [];
            $settings['multiple'] = false;
            DB::table('form_fields')->where('id', $field->id)->update([
                'type' => 'select',
                'options' => json_encode(self::OPTIONS, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }

        // EventSetupView also reads enabled_fields, so keep its cached form definition in sync.
        foreach (DB::table('events')->whereNotNull('enabled_fields')->get(['id', 'enabled_fields']) as $event) {
            $enabledFields = json_decode($event->enabled_fields ?: '[]', true);
            if (! is_array($enabledFields)) continue;
            $changed = false;
            foreach ($enabledFields as &$field) {
                if (strtolower((string) ($field['type'] ?? '')) !== 'number' || ! preg_match('/\bage\b/i', (string) ($field['label'] ?? ''))) continue;
                $field['type'] = 'Select';
                $field['options'] = self::OPTIONS;
                $field['settings'] = [...(array) ($field['settings'] ?? []), 'multiple' => false];
                $changed = true;
            }
            unset($field);
            if ($changed) {
                DB::table('events')->where('id', $event->id)->update([
                    'enabled_fields' => json_encode($enabledFields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep age-group answers intact; converting back to numeric would make saved answers invalid.
    }
};
