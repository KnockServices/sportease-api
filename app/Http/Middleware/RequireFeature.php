<?php

namespace App\Http\Middleware;

use App\Models\Plan;
use App\Services\Tenant;
use Closure;
use Illuminate\Http\Request;

/** Plan feature gate, e.g. ->middleware('feature:tournaments'). */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature)
    {
        $org = Tenant::org();
        abort_unless($org?->plan?->has($feature), 403, (Plan::FEATURES[$feature] ?? ucfirst($feature)).' is not included in the '.($org?->plan?->name ?? 'current').' plan. Upgrade under Subscription.');

        return $next($request);
    }
}
