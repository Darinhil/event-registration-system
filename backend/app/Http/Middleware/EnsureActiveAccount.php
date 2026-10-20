<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless(($user?->account_status ?? 'active') === 'active', 403, 'This account is suspended. Contact an administrator.');
        abort_if($user?->staffRecord()->where('employment_status', 'inactive')->exists(), 403, 'This staff account is inactive. Contact an administrator.');

        return $next($request);
    }
}
