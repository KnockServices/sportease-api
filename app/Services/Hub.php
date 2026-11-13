<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ClientEvent;
use App\Models\Setting;
use App\Models\StaffAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Small cross-cutting helpers: client journey, billing audit trail, staff alerts,
 * settings and sequential codes.
 */
class Hub
{
    public static function journey(?int $clientId, string $type, string $description, array $meta = [], ?int $familyId = null, ?int $leadId = null): void
    {
        ClientEvent::create([
            'client_id' => $clientId,
            'family_id' => $familyId,
            'lead_id' => $leadId,
            'type' => $type,
            'description' => $description,
            'actor_id' => auth()->id(),
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }

    public static function audit(string $action, ?Model $subject, ?int $familyId, ?float $amount, string $description, array $meta = []): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'family_id' => $familyId,
            'amount' => $amount,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }

    /** Alert a specific user, or everyone holding $role (admins always see role alerts). */
    public static function alert(string $type, string $title, ?string $body = null, ?string $link = null, ?int $userId = null, ?string $role = null): void
    {
        StaffAlert::create(compact('type', 'title', 'body', 'link') + ['user_id' => $userId, 'role' => $role]);
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        $value = Setting::where('key', $key)->value('value');

        return $value === null ? $default : json_decode($value, true);
    }

    public static function setSetting(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
    }

    /** Client ID prefix for the current academy, e.g. "KNA-" (setting `clients.prefix`, else the academy initials). */
    public static function clientPrefix(): string
    {
        $saved = self::setting('clients.prefix');
        if ($saved) {
            return rtrim($saved, '-').'-';
        }
        $words = preg_split('/\s+/', trim(Tenant::name()));
        $initials = strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 3))));

        return (strlen($initials) >= 2 ? $initials : strtoupper(substr(Tenant::name(), 0, 3))).'-';
    }

    /** Next sequential code like FAM-00012 for the given table/column. */
    public static function sequence(string $table, string $column, string $prefix, int $pad = 5): string
    {
        $max = DB::table($table)->where($column, 'like', $prefix.'%')
            ->when(Tenant::id() && $table !== 'subscription_invoices', fn ($q) => $q->where('organization_id', Tenant::id()))
            ->max($column);
        $n = $max ? ((int) substr($max, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, $pad, '0', STR_PAD_LEFT);
    }
}
