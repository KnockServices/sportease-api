<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 & 3 — courts, teams, packages, sessions, trials, registrations, reservations,
 * billing, POS/inventory, expenses, attendance, tasks, waiting list, HR and payroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('courts')->cascadeOnDelete();
            $t->string('name');
            $t->string('kind')->default('full'); // full, half, quarter
            $t->decimal('hourly_rate', 10, 2)->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('age_group')->nullable();
            $t->unsignedInteger('capacity')->default(15);
            $t->string('color')->default('#f97316');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('team_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('weekday'); // 0 = Sunday … 6 = Saturday
            $t->time('start_time');
            $t->time('end_time');
            $t->timestamps();
        });

        Schema::create('team_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('team_schedule_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('substitute_coach_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->dateTime('original_starts_at')->nullable();
            $t->string('status')->default('scheduled'); // scheduled, canceled, rescheduled, removed
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->unique(['team_schedule_id', 'original_starts_at']);
        });

        Schema::create('packages', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('price', 10, 2);
            $t->unsignedInteger('duration_months')->default(1);
            $t->unsignedInteger('sessions_per_week')->default(2);
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('trials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('scheduled_at');
            $t->unsignedInteger('duration_min')->default(60);
            $t->unsignedTinyInteger('rating')->nullable(); // 0-10
            $t->text('coach_notes')->nullable();
            $t->string('status')->default('scheduled'); // scheduled, approved, rejected, canceled, no_show
            $t->timestamp('reminder_sent_at')->nullable();
            $t->timestamp('day_before_reminder_sent_at')->nullable();
            $t->timestamp('evaluated_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // billed (parent/adult) client
            $t->string('source')->default('manual'); // registration, reservation, pos, shop, manual
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount_percent', 5, 2)->default(0);
            $t->decimal('discount_amount', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('paid_amount', 12, 2)->default(0);
            $t->string('status')->default('unpaid'); // unpaid, partial, paid, void
            $t->date('due_date');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_price', 12, 2);
            $t->decimal('total', 12, 2);
            $t->nullableMorphs('itemable');
            $t->timestamps();
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->string('method'); // cash, card, wallet, transfer, gateway
            $t->string('reference')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2); // + top-up, - charge
            $t->string('type'); // topup, charge, refund, adjustment
            $t->decimal('balance_after', 12, 2);
            $t->string('reference')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('temporary_credits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->date('expires_at');
            $t->string('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('registrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('package_id')->constrained()->cascadeOnDelete();
            $t->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->string('preferred_schedule')->nullable();
            $t->decimal('price', 10, 2);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status')->default('active'); // active, ended, canceled
            $t->foreignId('renewed_from_id')->nullable()->constrained('registrations')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('court_id')->constrained()->cascadeOnDelete();
            $t->foreignId('family_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->string('contact_name')->nullable();
            $t->dateTime('starts_at');
            $t->dateTime('ends_at');
            $t->dateTime('original_starts_at')->nullable();
            $t->decimal('price', 10, 2)->default(0);
            $t->string('status')->default('confirmed'); // confirmed, canceled, rescheduled, removed
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->string('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku')->unique();
            $t->string('barcode')->nullable()->unique();
            $t->string('name');
            $t->string('category')->nullable();
            $t->decimal('price', 10, 2);
            $t->decimal('cost', 10, 2)->default(0);
            $t->boolean('show_online')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->integer('quantity')->default(0);
            $t->integer('reorder_level')->default(5);
            $t->timestamps();
            $t->unique(['product_id', 'location_id']);
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->integer('change');
            $t->string('reason'); // sale, restock, adjustment, order
            $t->string('reference')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('sales', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('family_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('total', 12, 2);
            $t->string('method'); // cash, card, wallet, account
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->integer('quantity');
            $t->decimal('unit_price', 10, 2);
            $t->decimal('total', 12, 2);
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category');
            $t->string('description');
            $t->decimal('amount', 12, 2);
            $t->date('expense_date');
            $t->string('status')->default('pending'); // pending, approved, paid, canceled
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('status'); // present, absent, late, excused
            $t->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['team_session_id', 'client_id']);
        });

        Schema::create('follow_up_tasks', function (Blueprint $t) {
            $t->id();
            $t->string('type'); // absence, meeting_request, conversion, waiting_list, general
            $t->string('title');
            $t->text('description')->nullable();
            $t->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_date')->nullable();
            $t->string('status')->default('pending'); // pending, canceled, done
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('waiting_list_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('waiting'); // waiting, offered, accepted, declined, expired
            $t->timestamp('offered_at')->nullable();
            $t->timestamp('offer_expires_at')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->string('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('staff_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('check_in_at');
            $t->decimal('check_in_lat', 10, 7)->nullable();
            $t->decimal('check_in_lng', 10, 7)->nullable();
            $t->unsignedInteger('check_in_distance_m')->nullable();
            $t->dateTime('check_out_at')->nullable();
            $t->decimal('check_out_lat', 10, 7)->nullable();
            $t->decimal('check_out_lng', 10, 7)->nullable();
            $t->unsignedInteger('check_out_distance_m')->nullable();
            $t->string('status')->default('ok'); // ok, attention_required, approved, rejected
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
        });

        Schema::create('payrolls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('month', 7); // YYYY-MM
            $t->string('pay_type');
            $t->decimal('pay_rate', 10, 2);
            $t->decimal('days_worked', 6, 2)->default(0);
            $t->decimal('hours_worked', 8, 2)->default(0);
            $t->decimal('earned', 12, 2)->default(0);
            $t->decimal('carried_over', 12, 2)->default(0);
            $t->decimal('paid', 12, 2)->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'month']);
        });

        Schema::create('payroll_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->date('paid_on');
            $t->string('method')->default('cash');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'payroll_payments', 'payrolls', 'staff_attendances', 'waiting_list_entries', 'follow_up_tasks',
            'attendances', 'expenses', 'sale_items', 'sales', 'stock_movements', 'inventory_items', 'products',
            'reservations', 'registrations', 'temporary_credits', 'wallet_transactions', 'payments',
            'invoice_items', 'invoices', 'trials', 'packages', 'team_sessions', 'team_schedules', 'teams', 'courts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
