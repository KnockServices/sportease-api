<?php

namespace App\Validation;

use App\Services\Tenant;
use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * `exists` and `unique` rules only look inside the current organization, so one academy
 * can never reference another academy's records and codes are unique per academy.
 * Staff emails stay globally unique because they are the login.
 */
class TenantPresenceVerifier extends DatabasePresenceVerifier
{
    public const TABLES = [
        'users', 'locations', 'courts', 'court_rates', 'teams', 'team_schedules', 'team_sessions', 'packages',
        'leads', 'trials', 'families', 'clients', 'client_events', 'registrations', 'package_sessions', 'waiting_list_entries',
        'reservations', 'attendances', 'invoices', 'invoice_items', 'payments', 'wallet_transactions', 'temporary_credits',
        'products', 'inventory_items', 'stock_movements', 'sales', 'sale_items', 'expenses', 'staff_attendances',
        'payrolls', 'payroll_payments', 'follow_up_tasks', 'communication_logs', 'audit_logs', 'staff_alerts',
        'tournaments', 'tournament_teams', 'tournament_matches', 'settings',
    ];

    private bool $global = false;

    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        $this->global = $collection === 'users' && $column === 'email';
        try {
            return parent::getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
        } finally {
            $this->global = false;
        }
    }

    protected function table($table)
    {
        $q = parent::table($table);
        if (! $this->global && Tenant::id() && in_array($table, self::TABLES, true)) {
            $q->where('organization_id', Tenant::id());
        }

        return $q;
    }
}
