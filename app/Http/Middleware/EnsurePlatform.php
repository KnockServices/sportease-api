<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** SaaS owner (platform team) only. */
class EnsurePlatform
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->role === 'platform', 403, 'Platform console only.');

        return $next($request);
    }
}
