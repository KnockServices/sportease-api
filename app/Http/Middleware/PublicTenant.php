<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\Subscription;
use App\Services\Tenant;
use Closure;
use Illuminate\Http\Request;

/** Public academy endpoints (website lead forms): /api/public/{academy-slug}/… */
class PublicTenant
{
    public function handle(Request $request, Closure $next)
    {
        $org = Organization::with('plan')->where('slug', $request->route('academy'))->first();
        abort_unless($org && Subscription::canOperate($org), 404, 'Academy not found.');
        Tenant::set($org);
        $request->route()->forgetParameter('academy');

        return $next($request);
    }
}
