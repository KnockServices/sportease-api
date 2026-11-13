<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\Subscription;
use App\Services\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * Binds the signed-in staff member's academy. Academies whose trial ended or whose
 * subscription lapsed past the grace period can only reach their account & billing.
 */
class ResolveTenant
{
    private const ALWAYS_OPEN = ['api/auth/me', 'api/auth/logout', 'api/subscription', 'api/subscription/*', 'api/lookups'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_if($user?->role === 'platform', 403, 'Platform accounts work in the platform console.');
        $org = $user ? Organization::with('plan')->find($user->organization_id) : null;
        abort_unless($org, 403, 'This account is not linked to an academy.');
        Tenant::set($org);

        if (! Subscription::canOperate($org) && ! $request->is(...self::ALWAYS_OPEN)) {
            return response()->json([
                'message' => match (Subscription::status($org)) {
                    'trial_expired' => 'Your free trial has ended. Choose a plan to keep using Knock Sport Hub.',
                    'canceled' => 'This subscription was canceled. Choose a plan to reactivate the academy.',
                    default => 'The subscription is suspended for non-payment. Settle the open invoice to restore access.',
                },
                'code' => 'subscription_required',
            ], 402);
        }

        return $next($request);
    }
}
