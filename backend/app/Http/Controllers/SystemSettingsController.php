<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    private const DEFAULTS = [
        'site_name' => 'LLC-Event',
        'registration_notice' => '',
        'maintenance_mode' => false,
    ];

    /** Current values merged over the defaults. Every key is safe to show publicly. */
    public static function values(): array
    {
        $settings = self::DEFAULTS;
        foreach (SystemSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->get() as $setting) {
            $settings[$setting->key] = is_array($setting->value) && array_key_exists('value', $setting->value) ? $setting->value['value'] : $setting->value;
        }
        $settings['maintenance_mode'] = (bool) $settings['maintenance_mode'];
        return $settings;
    }

    public static function maintenanceMode(): bool
    {
        return self::values()['maintenance_mode'];
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => self::values()]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->merge(['site_name' => trim((string) $request->input('site_name')), 'registration_notice' => trim((string) $request->input('registration_notice'))]);
        $data = $request->validate([
            'site_name' => ['required', 'string', 'max:150'],
            'registration_notice' => ['nullable', 'string', 'max:1000'],
            'maintenance_mode' => ['boolean'],
        ]);
        foreach (self::DEFAULTS as $key => $default) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => ['value' => $data[$key] ?? $default]]);
        }
        return $this->show();
    }
}
