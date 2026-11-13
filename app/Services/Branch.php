<?php

namespace App\Services;

use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The branch the current request works in.
 *  - Admins, managers and accountants can switch branch (X-Branch-Id header) or see all branches.
 *  - Everyone else (reception, coaches) is locked to the branch on their staff account.
 */
class Branch
{
    public const ANY_BRANCH_ROLES = ['admin', 'manager', 'accountant'];

    public static function id(): ?int
    {
        return app()->bound('branch.id') ? app('branch.id') : null;
    }

    public static function canSwitch(?User $user): bool
    {
        return ! $user || in_array($user->role, self::ANY_BRANCH_ROLES, true) || ! $user->location_id;
    }

    public static function resolve(Request $request): ?int
    {
        $user = $request->user();
        if (! self::canSwitch($user)) {
            return $user->location_id;
        }
        $id = (int) $request->header('X-Branch-Id');

        return $id && Location::whereKey($id)->exists() ? $id : null;
    }

    /** Which branch a record belongs to, for stamping invoices etc. */
    public static function of(?Model $m): ?int
    {
        return match (true) {
            ! $m => null,
            $m instanceof \App\Models\Reservation, $m instanceof \App\Models\TeamSession, $m instanceof \App\Models\Trial => $m->court?->location_id,
            $m instanceof \App\Models\TournamentTeam => $m->tournament?->location_id,
            isset($m->location_id) => $m->location_id,
            $m instanceof \App\Models\Registration => $m->team?->location_id,
            default => null,
        };
    }

    /** Branch to use for a new record when nothing more specific is known. */
    public static function fallback(): ?int
    {
        if ($id = self::id() ?? auth()->user()?->location_id) {
            return $id;
        }
        // Single-branch academies (e.g. public web forms): that branch.
        $only = Location::where('active', true)->limit(2)->pluck('id');

        return $only->count() === 1 ? $only->first() : null;
    }
}
