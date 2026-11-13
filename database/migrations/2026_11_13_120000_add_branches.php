<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branches: each location becomes a branch with its own details and manager, and the
 * records that belong to one place (invoices, leads, registrations) carry their branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $t) {
            $t->string('code', 10)->nullable()->unique()->after('id');
            $t->string('phone')->nullable()->after('address');
            $t->string('email')->nullable()->after('phone');
            $t->foreignId('manager_id')->nullable()->after('email')->constrained('users')->nullOnDelete();
            $t->time('opens_at')->nullable()->after('manager_id');
            $t->time('closes_at')->nullable()->after('opens_at');
            $t->text('notes')->nullable()->after('active');
        });
        foreach (['invoices', 'leads', 'registrations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('location_id')->nullable()->after('id')->constrained()->nullOnDelete());
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'leads', 'registrations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('location_id'));
        }
        Schema::table('locations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('manager_id');
            $t->dropColumn(['code', 'phone', 'email', 'opens_at', 'closes_at', 'notes']);
        });
    }
};
