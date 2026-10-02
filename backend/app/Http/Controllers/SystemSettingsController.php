<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    private const DEFAULTS = [
        'site_name' => 'Event Registration System',
        'registration_notice' => '',
        'maintenance_mode' => false,
    ];

    public function show(): JsonResponse
    {
        $settings = self::DEFAULTS;
        SystemSetting::query()->get()->each(fn (SystemSetting $setting) => $settings[$setting->key] = $setting->value['value'] ?? $setting->value);
        return response()->json(['data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
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
