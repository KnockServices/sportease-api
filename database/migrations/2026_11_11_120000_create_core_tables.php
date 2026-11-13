<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Core foundation: locations, staff, families, clients, leads, trials,
 * settings, journey, audit and communication logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('address')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->unsignedInteger('geofence_radius_m')->default(150);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('reception'); // admin, manager, reception, coach, accountant
            $t->string('phone')->nullable();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->string('pay_type')->default('monthly'); // daily, hourly, monthly
            $t->decimal('pay_rate', 10, 2)->default(0);
            $t->boolean('active')->default(true);
        });

        Schema::create('families', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->decimal('discount_percent', 5, 2)->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('first_name');
            $t->string('last_name')->nullable();
            $t->string('phone');
            $t->string('email')->nullable();
            $t->string('participant_name')->nullable(); // e.g. the child the trial is for
            $t->date('participant_dob')->nullable();
            $t->string('source')->default('manual'); // web, facebook, instagram, whatsapp, walk_in, manual
            $t->string('status')->default('new'); // new, trial_scheduled, trial_approved, trial_rejected, converted, lost
            $t->text('notes')->nullable();
            $t->foreignId('family_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('family_id')->constrained()->cascadeOnDelete();
            $t->string('code')->unique();
            $t->string('type'); // parent, adult, child
            $t->string('first_name');
            $t->string('last_name');
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->date('date_of_birth')->nullable();
            $t->string('gender')->nullable();
            $t->boolean('billing_flag')->default(false);
            $t->decimal('wallet_balance', 12, 2)->default(0);
            $t->string('verification_code')->nullable();
            $t->string('verification_token')->nullable()->unique();
            $t->timestamp('phone_verified_at')->nullable();
            $t->string('status')->default('active'); // active, inactive
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value')->nullable();
        });

        Schema::create('client_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('family_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('description');
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->json('meta')->nullable();
            $t->timestamp('occurred_at')->useCurrent();
            $t->index(['client_id', 'occurred_at']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->nullableMorphs('auditable');
            $t->foreignId('family_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 12, 2)->nullable();
            $t->string('description')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at', 3)->useCurrent();
        });

        Schema::create('communication_logs', function (Blueprint $t) {
            $t->id();
            $t->string('channel'); // sms, email, whatsapp, push
            $t->string('recipient');
            $t->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $t->string('template')->nullable();
            $t->string('subject')->nullable();
            $t->text('body');
            $t->string('status')->default('sent'); // queued, sent, failed
            $t->string('provider')->default('log');
            $t->text('error')->nullable();
            $t->timestamps();
        });

        Schema::create('staff_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = everyone with the role
            $t->string('role')->nullable();
            $t->string('type');
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('link')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['staff_alerts', 'communication_logs', 'audit_logs', 'client_events', 'settings', 'clients', 'leads', 'families'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('location_id');
            $t->dropColumn(['role', 'phone', 'pay_type', 'pay_rate', 'active']);
        });
        Schema::dropIfExists('locations');
    }
};
