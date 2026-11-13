<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Court IDs (C-01, C-01-A …) so sub-courts visibly reference their full court.
 * - Duration-based court pricing tiers (the reservation pricing engine).
 * - Reservation discount / total after discount.
 * - Session-based (non-team) packages with a session counter and a usage log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $t) {
            $t->string('code', 20)->nullable()->unique()->after('id');
        });

        Schema::create('court_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('court_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('duration_min');
            $t->decimal('price', 10, 2);
            $t->timestamps();
            $t->unique(['court_id', 'duration_min']);
        });

        Schema::table('reservations', function (Blueprint $t) {
            $t->unsignedInteger('duration_min')->nullable()->after('ends_at');
            $t->string('pricing_note')->nullable()->after('price');
            $t->decimal('discount_percent', 5, 2)->default(0)->after('pricing_note');
            $t->decimal('discount_amount', 10, 2)->default(0)->after('discount_percent');
            $t->decimal('total', 10, 2)->default(0)->after('discount_amount');
        });

        Schema::table('packages', function (Blueprint $t) {
            $t->string('type')->default('team')->after('name'); // team | sessions
            $t->unsignedInteger('sessions_count')->nullable()->after('sessions_per_week');
            $t->unsignedInteger('session_minutes')->nullable()->after('sessions_count');
        });

        Schema::table('registrations', function (Blueprint $t) {
            $t->unsignedInteger('sessions_total')->nullable()->after('end_date');
            $t->unsignedInteger('sessions_used')->default(0)->after('sessions_total');
        });

        Schema::create('package_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $t->foreignId('client_id')->constrained()->cascadeOnDelete();
            $t->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('held_at');
            $t->unsignedInteger('duration_min')->default(60);
            $t->string('status')->default('attended'); // attended, no_show (both consume a session), excused (does not)
            $t->string('note', 500)->nullable();
            $t->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_sessions');
        Schema::table('registrations', fn (Blueprint $t) => $t->dropColumn(['sessions_total', 'sessions_used']));
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn(['type', 'sessions_count', 'session_minutes']));
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn(['duration_min', 'pricing_note', 'discount_percent', 'discount_amount', 'total']));
        Schema::dropIfExists('court_rates');
        Schema::table('courts', fn (Blueprint $t) => $t->dropColumn('code'));
    }
};
