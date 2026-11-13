<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tournaments: entries (academy teams or outside teams), fixtures on courts, results. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name');
            $t->string('age_group')->nullable();
            $t->string('format')->default('league'); // league, knockout, groups_knockout
            $t->string('status')->default('draft'); // draft, open, in_progress, completed, canceled
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->unsignedInteger('max_teams')->nullable();
            $t->decimal('entry_fee', 10, 2)->default(0);
            $t->unsignedInteger('match_minutes')->default(60);
            $t->unsignedInteger('break_minutes')->default(15);
            $t->unsignedInteger('group_count')->default(2);
            $t->unsignedInteger('advance_per_group')->default(2);
            $t->unsignedInteger('points_win')->default(2);
            $t->unsignedInteger('points_draw')->default(1);
            $t->unsignedInteger('points_loss')->default(1);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('tournament_teams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $t->foreignId('team_id')->nullable()->constrained()->nullOnDelete(); // academy team, or null for an outside team
            $t->string('name');
            $t->string('contact_name')->nullable();
            $t->string('contact_phone')->nullable();
            $t->foreignId('family_id')->nullable()->constrained()->nullOnDelete(); // billed family for the entry fee
            $t->string('group', 4)->nullable();
            $t->unsignedInteger('seed')->nullable();
            $t->decimal('entry_fee', 10, 2)->default(0);
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('confirmed'); // confirmed, withdrawn
            $t->timestamps();
            $t->unique(['tournament_id', 'name']);
        });

        Schema::create('tournament_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $t->string('stage')->default('league'); // league, group, knockout
            $t->string('group', 4)->nullable();
            $t->unsignedInteger('round');
            $t->string('round_name')->nullable();
            $t->unsignedInteger('position')->default(0); // slot within the round (bracket order)
            $t->foreignId('home_id')->nullable()->constrained('tournament_teams')->nullOnDelete();
            $t->foreignId('away_id')->nullable()->constrained('tournament_teams')->nullOnDelete();
            $t->unsignedInteger('home_score')->nullable();
            $t->unsignedInteger('away_score')->nullable();
            $t->foreignId('winner_id')->nullable()->constrained('tournament_teams')->nullOnDelete();
            $t->foreignId('next_match_id')->nullable()->constrained('tournament_matches')->nullOnDelete();
            $t->string('next_slot', 4)->nullable(); // home | away
            $t->foreignId('court_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->foreignId('referee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status')->default('scheduled'); // scheduled, completed, bye, canceled
            $t->string('note', 500)->nullable();
            $t->timestamps();
            $t->index(['court_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_matches');
        Schema::dropIfExists('tournament_teams');
        Schema::dropIfExists('tournaments');
    }
};
