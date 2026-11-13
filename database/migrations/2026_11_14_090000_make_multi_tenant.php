<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS: every academy is an organization (tenant) on a subscription plan. All business
 * tables carry organization_id; codes and numbers are unique per organization.
 */
return new class extends Migration
{
    public const TENANT_TABLES = [
        'users', 'locations', 'courts', 'court_rates', 'teams', 'team_schedules', 'team_sessions', 'packages',
        'leads', 'trials', 'families', 'clients', 'client_events', 'registrations', 'package_sessions', 'waiting_list_entries',
        'reservations', 'attendances', 'invoices', 'invoice_items', 'payments', 'wallet_transactions', 'temporary_credits',
        'products', 'inventory_items', 'stock_movements', 'sales', 'sale_items', 'expenses', 'staff_attendances',
        'payrolls', 'payroll_payments', 'follow_up_tasks', 'communication_logs', 'audit_logs', 'staff_alerts',
        'tournaments', 'tournament_teams', 'tournament_matches',
    ];

    /** [table, old unique index, new unique columns] */
    private const PER_TENANT_UNIQUE = [
        ['families', 'families_code_unique', ['code']],
        ['clients', 'clients_code_unique', ['code']],
        ['products', 'products_sku_unique', ['sku']],
        ['products', 'products_barcode_unique', ['barcode']],
        ['sales', 'sales_number_unique', ['number']],
        ['courts', 'courts_code_unique', ['code']],
        ['tournaments', 'tournaments_code_unique', ['code']],
        ['locations', 'locations_code_unique', ['code']],
        ['invoices', 'invoices_number_unique', ['number']],
    ];

    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->string('tagline')->nullable();
            $t->decimal('price_monthly', 10, 2)->default(0);
            $t->decimal('price_yearly', 10, 2)->default(0);
            $t->unsignedInteger('max_branches')->nullable(); // null = unlimited
            $t->unsignedInteger('max_staff')->nullable();
            $t->unsignedInteger('max_clients')->nullable();
            $t->json('features')->nullable(); // pos, tournaments, payroll, reports, inventory
            $t->unsignedInteger('trial_days')->default(14);
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('public')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug', 60)->unique();
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('billing_cycle')->default('monthly'); // monthly | yearly
            $t->string('status')->default('trialing'); // trialing, active, past_due, suspended, canceled
            $t->dateTime('trial_ends_at')->nullable();
            $t->dateTime('current_period_end')->nullable();
            $t->dateTime('cancel_at')->nullable();
            $t->string('owner_name')->nullable();
            $t->string('owner_email')->nullable();
            $t->string('phone')->nullable();
            $t->string('country', 60)->nullable();
            $t->string('currency', 3)->default('USD');
            $t->string('timezone')->default('Asia/Beirut');
            $t->string('brand_color', 9)->nullable();
            $t->text('platform_notes')->nullable();
            $t->timestamps();
        });

        Schema::create('subscription_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number')->unique();
            $t->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('billing_cycle')->default('monthly');
            $t->decimal('amount', 10, 2);
            $t->date('period_start');
            $t->date('period_end');
            $t->date('due_date');
            $t->string('status')->default('pending'); // pending, paid, void
            $t->string('kind')->default('renewal'); // renewal, change, first
            $t->dateTime('paid_at')->nullable();
            $t->string('method')->nullable();
            $t->string('reference')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        foreach (self::TENANT_TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
                $t->index('organization_id');
            });
        }
        foreach (self::PER_TENANT_UNIQUE as [$table, $index, $cols]) {
            Schema::table($table, function (Blueprint $t) use ($index, $cols) {
                $t->dropUnique($index);
                $t->unique(array_merge(['organization_id'], $cols));
            });
        }

        // Settings were keyed globally; they are now per organization.
        Schema::drop('settings');
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('key');
            $t->text('value')->nullable();
            $t->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::drop('settings');
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });
        foreach (self::PER_TENANT_UNIQUE as [$table, $index, $cols]) {
            Schema::table($table, function (Blueprint $t) use ($cols) {
                $t->dropUnique(array_merge(['organization_id'], $cols));
                $t->unique($cols);
            });
        }
        foreach (self::TENANT_TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        }
        Schema::dropIfExists('subscription_invoices');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('plans');
    }
};
