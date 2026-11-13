<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentTeam;
use App\Services\Billing;
use App\Services\Hub;
use App\Services\Scheduling;
use App\Services\Tournaments;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Tournaments: setup, entries & entry fees, fixtures, court schedule, results, standings. */
class TournamentController extends Controller
{
    public function index(Request $request)
    {
        return Tournament::with('location:id,name')->inBranch(null, true)
            ->withCount(['teams' => fn ($q) => $q->where('status', 'confirmed'), 'matches' => fn ($q) => $q->where('status', '!=', 'bye'), 'matches as played_count' => fn ($q) => $q->where('status', 'completed')])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('starts_on')->get();
    }

    public function show(Tournament $tournament)
    {
        $tournament->load('location:id,name', 'teams.team:id,name,color', 'teams.family:id,code,name', 'teams.invoice:id,number,status,total,paid_amount');
        $matches = $tournament->matches()->with('home:id,name,team_id', 'away:id,name,team_id', 'court:id,code,name,kind,parent_id', 'court.parent:id,code,name', 'referee:id,name')->get()
            ->each(fn ($m) => $m->setAttribute('court_label', $m->court?->label));
        $group = $matches->where('stage', 'group');

        return $tournament->toArray() + [
            'matches' => $matches->values(),
            'standings' => in_array($tournament->format, ['league', 'groups_knockout']) ? Tournaments::standings($tournament) : [],
            'can_draw_knockout' => $tournament->format === 'groups_knockout' && $group->isNotEmpty() && $group->where('status', '!=', 'completed')->isEmpty(),
            'champion' => ($final = $matches->where('stage', 'knockout')->whereNull('next_match_id')->first()) && $final->winner_id
                ? $tournament->teams->firstWhere('id', $final->winner_id)?->name
                : ($tournament->format === 'league' && $tournament->status === 'completed' ? (Tournaments::standings($tournament)[0]['rows'][0]['name'] ?? null) : null),
        ];
    }

    public function save(Request $request, ?Tournament $tournament = null)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'age_group' => 'nullable|string|max:40',
            'format' => ['required', Rule::in(['league', 'knockout', 'groups_knockout'])],
            'status' => ['nullable', Rule::in(['draft', 'open', 'in_progress', 'completed', 'canceled'])],
            'location_id' => 'nullable|exists:locations,id',
            'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on',
            'max_teams' => 'nullable|integer|min:2|max:64', 'entry_fee' => 'nullable|numeric|min:0',
            'match_minutes' => 'nullable|integer|min:10|max:240', 'break_minutes' => 'nullable|integer|min:0|max:120',
            'group_count' => 'nullable|integer|min:1|max:8', 'advance_per_group' => 'nullable|integer|min:1|max:4',
            'points_win' => 'nullable|integer|min:0|max:10', 'points_draw' => 'nullable|integer|min:0|max:10', 'points_loss' => 'nullable|integer|min:0|max:10',
            'notes' => 'nullable|string',
        ]);
        $data = array_filter($data, fn ($v) => $v !== null) + ['notes' => $data['notes'] ?? null, 'max_teams' => $data['max_teams'] ?? null];

        if ($tournament) {
            if ($tournament->format !== $data['format'] && $tournament->matches()->exists()) {
                throw ValidationException::withMessages(['format' => 'Fixtures already exist: regenerate them after changing the format, or delete them first.']);
            }
            $tournament->update($data);
        } else {
            $tournament = Tournament::create($data + ['code' => Hub::sequence('tournaments', 'code', 'TRN-', 4), 'status' => 'draft', 'created_by' => $request->user()->id]);
        }

        return $tournament;
    }

    // ---- Entries ----------------------------------------------------------------------

    public function addTeam(Request $request, Tournament $tournament)
    {
        $data = $request->validate([
            'team_id' => 'nullable|exists:teams,id', 'name' => 'nullable|string|max:120',
            'contact_name' => 'nullable|string|max:120', 'contact_phone' => 'nullable|string|max:40',
            'family_id' => 'nullable|exists:families,id', 'seed' => 'nullable|integer|min:1|max:64', 'entry_fee' => 'nullable|numeric|min:0',
        ]);
        $name = $data['name'] ?? ($data['team_id'] ? Team::find($data['team_id'])->name : null);
        if (! $name) {
            throw ValidationException::withMessages(['name' => 'Pick an academy team or enter the outside team name.']);
        }
        if ($tournament->teams()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "{$name} is already entered."]);
        }
        if ($tournament->max_teams && $tournament->teams()->where('status', 'confirmed')->count() >= $tournament->max_teams) {
            throw ValidationException::withMessages(['name' => "The tournament is full ({$tournament->max_teams} teams)."]);
        }
        if ($tournament->matches()->exists()) {
            throw ValidationException::withMessages(['name' => 'Fixtures are already drawn: regenerate them after adding teams.']);
        }

        return $tournament->teams()->create(['name' => $name, 'entry_fee' => $data['entry_fee'] ?? $tournament->entry_fee] + $data);
    }

    public function updateTeam(Request $request, TournamentTeam $entry)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120', 'contact_name' => 'nullable|string|max:120', 'contact_phone' => 'nullable|string|max:40',
            'family_id' => 'nullable|exists:families,id', 'seed' => 'nullable|integer|min:1|max:64', 'entry_fee' => 'nullable|numeric|min:0',
            'status' => ['sometimes', Rule::in(['confirmed', 'withdrawn'])],
        ]);
        $entry->update($data);

        return $entry;
    }

    public function removeTeam(TournamentTeam $entry)
    {
        if ($entry->tournament->matches()->where(fn ($q) => $q->where('home_id', $entry->id)->orWhere('away_id', $entry->id))->exists()) {
            throw ValidationException::withMessages(['entry' => 'This team has fixtures: mark it withdrawn, or regenerate the fixtures after removing it.']);
        }
        $entry->delete();

        return response()->noContent();
    }

    /** Bill the entry fee to the team's family (outside teams fall back to the walk-in account). */
    public function invoiceEntry(TournamentTeam $entry)
    {
        if ($entry->invoice_id && $entry->invoice?->status !== 'void') {
            throw ValidationException::withMessages(['entry' => "Already invoiced ({$entry->invoice->number})."]);
        }
        if ($entry->entry_fee <= 0) {
            throw ValidationException::withMessages(['entry' => 'This entry has no fee.']);
        }
        $family = $entry->family ?? Family::where('code', 'WALK-IN')->firstOrFail();
        $invoice = Billing::invoice($family, [[
            'description' => "Tournament entry: {$entry->tournament->name} · {$entry->name}", 'unit_price' => $entry->entry_fee, 'itemable' => $entry,
        ]], 'tournament', 0, 7, $entry->contact_name ? "Contact: {$entry->contact_name} {$entry->contact_phone}" : null);
        $entry->update(['invoice_id' => $invoice->id]);

        return $invoice;
    }

    // ---- Fixtures, schedule, results ----------------------------------------------------

    public function generate(Request $request, Tournament $tournament)
    {
        return ['created' => Tournaments::generate($tournament, $request->boolean('reset'))];
    }

    public function knockout(Tournament $tournament)
    {
        return ['created' => Tournaments::generateKnockout($tournament)];
    }

    public function schedule(Request $request, Tournament $tournament)
    {
        $data = $request->validate([
            'court_ids' => 'required|array|min:1', 'court_ids.*' => 'exists:courts,id',
            'day_start' => 'required|date_format:H:i', 'day_end' => 'required|date_format:H:i|after:day_start',
            'from' => 'nullable|date', 'only_unscheduled' => 'boolean',
        ]);

        return ['scheduled' => Tournaments::schedule($tournament, $data['court_ids'], $data['day_start'], $data['day_end'], $request->boolean('only_unscheduled'), $data['from'] ?? null)];
    }

    public function updateMatch(Request $request, TournamentMatch $match)
    {
        $data = $request->validate([
            'court_id' => 'nullable|exists:courts,id', 'starts_at' => 'nullable|date', 'referee_id' => 'nullable|exists:users,id',
            'note' => 'nullable|string|max:500', 'status' => ['sometimes', Rule::in(['scheduled', 'canceled'])],
        ]);
        if (array_key_exists('starts_at', $data)) {
            $data['starts_at'] = $data['starts_at'] ? Carbon::parse($data['starts_at']) : null;
            $data['ends_at'] = $data['starts_at']?->copy()->addMinutes($match->tournament->match_minutes);
        }
        $court = array_key_exists('court_id', $data) ? $data['court_id'] : $match->court_id;
        $start = $data['starts_at'] ?? $match->starts_at;
        if ($court && $start && ($data['status'] ?? $match->status) === 'scheduled') {
            Scheduling::assertFree((int) $court, $start, $data['ends_at'] ?? $match->ends_at, ['tournament_match' => $match->id]);
        }
        $match->update($data);

        return $match;
    }

    public function result(Request $request, TournamentMatch $match)
    {
        $data = $request->validate(['home_score' => 'required|integer|min:0|max:500', 'away_score' => 'required|integer|min:0|max:500', 'note' => 'nullable|string|max:500']);

        return Tournaments::recordResult($match, $data['home_score'], $data['away_score'], $data['note'] ?? null);
    }

    public function clearResult(TournamentMatch $match)
    {
        Tournaments::clearResult($match);

        return response()->noContent();
    }
}
