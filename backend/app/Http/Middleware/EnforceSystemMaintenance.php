<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSystemMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isEnabled() || $request->user('sanctum')?->isMasterAdmin()) {
            return $next($request);
        }

        $allowedPaths = [
            'api/public-settings',
            'api/auth/login',
            'api/auth/admin/login',
            'api/auth/register',
        ];

        foreach ($allowedPaths as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'The system is temporarily unavailable while maintenance is in progress.',
            'maintenance_mode' => true,
        ], 503);
    }

    private function isEnabled(): bool
    {
        $value = SystemSetting::query()->where('key', 'maintenance_mode')->first()?->value;
        if (is_array($value)) {
            $value = $value['value'] ?? false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
