<?php

namespace App\Services;

use App\Models\Organization;

/**
 * The academy (organization) the current request or job works for. Every tenant model is
 * filtered to it by a global scope and new records are stamped with it.
 */
class Tenant
{
    private static ?Organization $org = null;

    public static function set(?Organization $org): void
    {
        self::$org = $org;
        if ($org?->timezone) {
            date_default_timezone_set($org->timezone);
            config(['app.timezone' => $org->timezone]);
        }
    }

    public static function org(): ?Organization
    {
        return self::$org;
    }

    public static function id(): ?int
    {
        return self::$org?->id;
    }

    public static function name(): string
    {
        return self::$org?->name ?? config('app.name');
    }

    /** Run $fn as $org (console jobs, platform tooling), restoring the previous tenant afterwards. */
    public static function run(Organization $org, callable $fn): mixed
    {
        $prev = self::$org;
        self::set($org);
        try {
            return $fn($org);
        } finally {
            self::set($prev);
        }
    }

    /** Run once for every organization that is allowed to operate. */
    public static function each(callable $fn): void
    {
        foreach (Organization::whereIn('status', ['trialing', 'active', 'past_due'])->get() as $org) {
            self::run($org, $fn);
        }
    }
}
