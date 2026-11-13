<?php

namespace App\Services;

use App\Models\Tournament;
use App\Models\TournamentMatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tournament engine.
 *  - league:          everyone plays everyone (circle method), one table.
 *  - knockout:        seeded single-elimination bracket; byes when the field is not a power of two.
 *  - groups_knockout: snake-seeded groups play a league, then the top N of each group
 *                     go into a cross-seeded knockout (A1 v B2, B1 v A2 …).
 * Matches are placed on courts with the same sub-court aware availability as bookings.
 */
class Tournaments
{
    public const GROUPS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];

    // ---- Fixtures ---------------------------------------------------------------

    public static function generate(Tournament $t, bool $reset = false): int
    {
        $teams = $t->teams()->where('status', 'confirmed')->get();
        if ($teams->count() < 2) {
            throw ValidationException::withMessages(['teams' => 'Add at least two confirmed teams first.']);
        }
        if (! $reset && $t->matches()->where('status', 'completed')->exists()) {
            throw ValidationException::withMessages(['fixtures' => 'Results are already recorded. Regenerating will delete them: confirm the reset to continue.']);
        }

        return DB::transaction(function () use ($t, $teams) {
            $t->matches()->update(['next_match_id' => null]);
            $t->matches()->delete();
            $count = 0;

            if ($t->format === 'knockout') {
                $count = self::bracket($t, $teams->pluck('id')->all(), 0);
            } elseif ($t->format === 'groups_knockout') {
                $groups = max(1, min((int) $t->group_count, intdiv($teams->count(), 2), count(self::GROUPS)));
                // Snake seeding: 1→A, 2→B, 3→B, 4→A … keeps groups balanced.
                foreach ($teams->values() as $i => $team) {
                    $row = intdiv($i, $groups);
                    $col = $i % $groups;
                    $team->update(['group' => self::GROUPS[$row % 2 ? $groups - 1 - $col : $col]]);
                }
                foreach ($teams->groupBy('group')->sortKeys() as $g => $members) {
                    $count += self::league($t, $members->pluck('id')->all(), 'group', $g);
                }
            } else {
                $teams->each->update(['group' => null]);
                $count = self::league($t, $teams->pluck('id')->all(), 'league', null);
            }

            if (in_array($t->status ?? 'draft', ['draft', 'open'])) {
                $t->update(['status' => 'open']);
            }

            return $count;
        });
    }

    /** Round robin by the circle method. */
    private static function league(Tournament $t, array $ids, string $stage, ?string $group): int
    {
        if (count($ids) % 2) {
            $ids[] = null; // bye
        }
        $n = count($ids);
        $count = 0;
        for ($r = 0; $r < $n - 1; $r++) {
            for ($i = 0; $i < $n / 2; $i++) {
                [$home, $away] = [$ids[$i], $ids[$n - 1 - $i]];
                if ($home === null || $away === null) {
                    continue;
                }
                if ($r % 2) {
                    [$home, $away] = [$away, $home]; // alternate home/away
                }
                TournamentMatch::create([
                    'tournament_id' => $t->id, 'stage' => $stage, 'group' => $group, 'round' => $r + 1,
                    'round_name' => ($group ? "Group {$group} · " : '').'Round '.($r + 1), 'position' => $i,
                    'home_id' => $home, 'away_id' => $away,
                ]);
                $count++;
            }
            // Rotate everyone except the first.
            $ids = array_merge([$ids[0]], [$ids[$n - 1]], array_slice($ids, 1, $n - 2));
        }

        return $count;
    }

    /** Seeded single-elimination bracket; $seeded is best-first. Round numbers start after $roundOffset. */
    private static function bracket(Tournament $t, array $seeded, int $roundOffset): int
    {
        $size = 2;
        while ($size < count($seeded)) {
            $size *= 2;
        }
        $order = [1];
        while (count($order) < $size) {
            $m = count($order) * 2 + 1;
            $order = collect($order)->flatMap(fn ($s) => [$s, $m - $s])->all();
        }
        $slots = array_map(fn ($s) => $seeded[$s - 1] ?? null, $order);

        $rounds = (int) log($size, 2);
        $prev = [];
        $count = 0;
        for ($r = 1; $r <= $rounds; $r++) {
            $inRound = $size >> $r;
            $current = [];
            for ($p = 0; $p < $inRound; $p++) {
                $current[] = TournamentMatch::create([
                    'tournament_id' => $t->id, 'stage' => 'knockout', 'round' => $roundOffset + $r,
                    'round_name' => self::roundName($inRound, $r), 'position' => $p,
                    'home_id' => $r === 1 ? $slots[$p * 2] : null, 'away_id' => $r === 1 ? $slots[$p * 2 + 1] : null,
                ]);
                $count++;
            }
            foreach ($prev as $i => $m) {
                $m->update(['next_match_id' => $current[intdiv($i, 2)]->id, 'next_slot' => $i % 2 ? 'away' : 'home']);
            }
            $prev = $current;
        }

        // Byes: a first-round slot without an opponent advances automatically.
        TournamentMatch::where('tournament_id', $t->id)->where('stage', 'knockout')->where('round', $roundOffset + 1)->get()
            ->each(function ($m) {
                if ($m->home_id xor $m->away_id) {
                    $m->update(['status' => 'bye', 'winner_id' => $m->home_id ?? $m->away_id]);
                    self::advance($m->fresh());
                }
            });

        return $count;
    }

    private static function roundName(int $matches, int $round): string
    {
        return match ($matches) {
            1 => 'Final', 2 => 'Semi-final', 4 => 'Quarter-final', 8 => 'Round of 16', default => "Round {$round}",
        };
    }

    /** Groups → knockout: top N of each group, cross-seeded so group winners meet runners-up. */
    public static function generateKnockout(Tournament $t): int
    {
        if ($t->format !== 'groups_knockout') {
            throw ValidationException::withMessages(['format' => 'Only group-stage tournaments have a separate knockout draw.']);
        }
        $group = $t->matches()->where('stage', 'group');
        if (! (clone $group)->exists() || (clone $group)->where('status', '!=', 'completed')->exists()) {
            throw ValidationException::withMessages(['knockout' => 'Finish every group match before drawing the knockout stage.']);
        }
        $tables = self::standings($t);
        $n = max(1, (int) $t->advance_per_group);
        $seeded = [];
        for ($rank = 0; $rank < $n; $rank++) {
            foreach ($tables as $table) {
                if (isset($table['rows'][$rank])) {
                    $seeded[] = $table['rows'][$rank]['team_id'];
                }
            }
        }
        if (count($seeded) < 2) {
            throw ValidationException::withMessages(['knockout' => 'Not enough qualified teams for a knockout.']);
        }

        return DB::transaction(function () use ($t, $seeded) {
            $t->matches()->where('stage', 'knockout')->update(['next_match_id' => null]);
            $t->matches()->where('stage', 'knockout')->delete();

            return self::bracket($t, $seeded, (int) $t->matches()->max('round'));
        });
    }

    // ---- Results ------------------------------------------------------------------

    public static function recordResult(TournamentMatch $m, int $home, int $away, ?string $note = null): TournamentMatch
    {
        if (! $m->home_id || ! $m->away_id) {
            throw ValidationException::withMessages(['match' => 'Both teams must be known before a result can be recorded.']);
        }
        if ($m->stage === 'knockout' && $home === $away) {
            throw ValidationException::withMessages(['home_score' => 'Knockout matches need a winner: enter the score after overtime.']);
        }
        self::assertNextNotPlayed($m);

        return DB::transaction(function () use ($m, $home, $away, $note) {
            $m->update([
                'home_score' => $home, 'away_score' => $away, 'status' => 'completed',
                'winner_id' => $home === $away ? null : ($home > $away ? $m->home_id : $m->away_id),
                'note' => $note ?? $m->note,
            ]);
            self::advance($m);
            self::refreshStatus($m->tournament);

            return $m->fresh();
        });
    }

    public static function clearResult(TournamentMatch $m): void
    {
        if ($m->status === 'bye') {
            throw ValidationException::withMessages(['match' => 'A bye cannot be cleared.']);
        }
        self::assertNextNotPlayed($m);
        DB::transaction(function () use ($m) {
            $m->update(['home_score' => null, 'away_score' => null, 'winner_id' => null, 'status' => 'scheduled']);
            if ($m->next_match_id) {
                $m->next->update([$m->next_slot.'_id' => null]);
            }
            self::refreshStatus($m->tournament);
        });
    }

    private static function assertNextNotPlayed(TournamentMatch $m): void
    {
        if ($m->next && $m->next->status === 'completed') {
            throw ValidationException::withMessages(['match' => "The next match ({$m->next->round_name}) already has a result: clear it first."]);
        }
    }

    /** Put the winner into the next bracket slot. */
    private static function advance(TournamentMatch $m): void
    {
        if ($m->next_match_id && $m->next_slot) {
            $m->next->update([$m->next_slot.'_id' => $m->winner_id]);
        }
    }

    private static function refreshStatus(Tournament $t): void
    {
        if ($t->status === 'canceled') {
            return;
        }
        $all = $t->matches()->get(['stage', 'status', 'next_match_id']);
        $done = $all->whereIn('status', ['completed', 'bye']);
        $finished = match ($t->format) {
            'league' => $all->isNotEmpty() && $done->count() === $all->count(),
            default => $all->where('stage', 'knockout')->whereNull('next_match_id')->where('status', 'completed')->isNotEmpty(),
        };
        $t->update(['status' => $finished ? 'completed' : ($done->where('status', 'completed')->isNotEmpty() ? 'in_progress' : 'open')]);
    }

    // ---- Standings -----------------------------------------------------------------

    /** @return array<int, array{group:?string, rows:array}> sorted by points, point difference, points scored. */
    public static function standings(Tournament $t): array
    {
        $teams = $t->teams()->where('status', 'confirmed')->get();
        $matches = $t->matches()->whereIn('stage', ['league', 'group'])->where('status', 'completed')->get();
        $out = [];

        foreach ($teams->groupBy(fn ($x) => $t->format === 'groups_knockout' ? ($x->group ?? '—') : '')->sortKeys() as $g => $members) {
            $rows = $members->mapWithKeys(fn ($x) => [$x->id => ['team_id' => $x->id, 'name' => $x->name, 'academy' => (bool) $x->team_id, 'p' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'pf' => 0, 'pa' => 0, 'diff' => 0, 'pts' => 0, 'form' => []]])->all();
            foreach ($matches as $m) {
                foreach ([[$m->home_id, $m->home_score, $m->away_score], [$m->away_id, $m->away_score, $m->home_score]] as [$id, $for, $against]) {
                    if (! isset($rows[$id])) {
                        continue;
                    }
                    $r = &$rows[$id];
                    $r['p']++;
                    $r['pf'] += $for;
                    $r['pa'] += $against;
                    $res = $for > $against ? 'w' : ($for < $against ? 'l' : 'd');
                    $r[$res]++;
                    $r['pts'] += ['w' => $t->points_win, 'd' => $t->points_draw, 'l' => $t->points_loss][$res];
                    $r['form'][] = strtoupper($res);
                    unset($r);
                }
            }
            $rows = collect($rows)->map(fn ($r) => ['diff' => $r['pf'] - $r['pa'], 'form' => array_slice($r['form'], -5)] + $r)
                ->sort(fn ($a, $b) => [$b['pts'], $b['diff'], $b['pf'], $a['name']] <=> [$a['pts'], $a['diff'], $a['pf'], $b['name']])
                ->values()->all();
            $out[] = ['group' => $g === '' ? null : $g, 'rows' => $rows];
        }

        return $out;
    }

    // ---- Court scheduling ------------------------------------------------------------

    /**
     * Place matches on the chosen courts, earliest first, between the daily hours and within
     * the tournament dates. Respects court availability (bookings, training, trials, other
     * matches), team rest between games and bracket order.
     */
    public static function schedule(Tournament $t, array $courtIds, string $dayStart, string $dayEnd, bool $onlyUnscheduled, ?string $from = null): int
    {
        $dur = (int) $t->match_minutes;
        $gap = (int) $t->break_minutes;

        return DB::transaction(function () use ($t, $courtIds, $dayStart, $dayEnd, $onlyUnscheduled, $from, $dur, $gap) {
            $all = $t->matches()->whereNotIn('status', ['bye', 'canceled'])->get()->keyBy('id');
            $todo = $all->where('status', 'scheduled')->filter(fn ($m) => ! $onlyUnscheduled || ! $m->starts_at);
            if ($todo->isEmpty()) {
                return 0;
            }
            TournamentMatch::whereIn('id', $todo->keys())->update(['starts_at' => null, 'ends_at' => null, 'court_id' => null]);
            // Reload so the cleared slots are the models' original state (keeps update() dirty-checks honest).
            $all = $t->matches()->whereNotIn('status', ['bye', 'canceled'])->get()->keyBy('id');
            $todo = $all->only($todo->keys()->all());

            $teamFree = [];
            $placedEnd = fn ($m) => $m->ends_at ? $m->ends_at->copy() : null;
            foreach ($all as $m) {
                if ($m->ends_at && ! $todo->has($m->id)) {
                    foreach ([$m->home_id, $m->away_id] as $id) {
                        if ($id && (! isset($teamFree[$id]) || $m->ends_at->gt($teamFree[$id]))) {
                            $teamFree[$id] = $m->ends_at->copy();
                        }
                    }
                }
            }

            $first = Carbon::parse(($from && Carbon::parse($from)->gt($t->starts_on) ? Carbon::parse($from) : $t->starts_on)->toDateString().' '.$dayStart);
            if ($first->lt(now())) {
                $first = now()->ceilMinutes(15);
            }
            $lastDay = $t->ends_on->copy()->endOfDay();
            // Knockout games wait for the whole league/group stage, including games already on the schedule.
            $leagueEnd = $all->filter(fn ($m) => $m->stage !== 'knockout' && $m->ends_at && ! $todo->has($m->id))->max('ends_at')?->copy();
            $count = 0;

            // League/group matches first (round by round), then the knockout bracket round by round.
            $ordered = $todo->sortBy(fn ($m) => [$m->stage === 'knockout' ? 1 : 0, $m->round, $m->position, $m->group])->values();
            foreach ($ordered as $m) {
                $earliest = $first->copy();
                foreach ([$m->home_id, $m->away_id] as $id) {
                    if ($id && isset($teamFree[$id])) {
                        $earliest = $earliest->max($teamFree[$id]->copy()->addMinutes($gap));
                    }
                }
                if ($m->stage === 'knockout') {
                    if ($leagueEnd) {
                        $earliest = $earliest->max($leagueEnd->copy()->addMinutes($gap));
                    }
                    foreach ($all->where('next_match_id', $m->id) as $feeder) {
                        if ($end = $placedEnd($feeder)) {
                            $earliest = $earliest->max($end->addMinutes($gap));
                        }
                    }
                }
                [$court, $start] = self::findSlot($courtIds, $earliest, $dur, $dayStart, $dayEnd, $lastDay, $m);
                $m->update(['court_id' => $court, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($dur)]);
                $all[$m->id] = $m;
                foreach ([$m->home_id, $m->away_id] as $id) {
                    if ($id) {
                        $teamFree[$id] = $m->ends_at->copy();
                    }
                }
                if ($m->stage !== 'knockout') {
                    $leagueEnd = $leagueEnd ? $leagueEnd->max($m->ends_at) : $m->ends_at->copy();
                }
                $count++;
            }

            return $count;
        });
    }

    private static function findSlot(array $courtIds, Carbon $t, int $dur, string $dayStart, string $dayEnd, Carbon $lastDay, TournamentMatch $m): array
    {
        $t = $t->copy()->ceilMinutes(15);
        for ($guard = 0; $guard < 5000; $guard++) {
            $open = Carbon::parse($t->toDateString().' '.$dayStart);
            $close = Carbon::parse($t->toDateString().' '.$dayEnd);
            if ($t->lt($open)) {
                $t = $open;
            }
            if ($t->copy()->addMinutes($dur)->gt($close)) {
                $t = $open->addDay();
                continue;
            }
            if ($t->gt($lastDay)) {
                break;
            }
            foreach ($courtIds as $court) {
                if (! Scheduling::conflicts((int) $court, $t, $t->copy()->addMinutes($dur), ['tournament_match' => $m->id])) {
                    return [(int) $court, $t];
                }
            }
            $t = $t->copy()->addMinutes(15);
        }
        throw ValidationException::withMessages(['schedule' => "Not enough free court time before {$lastDay->format('d M')} to place {$m->round_name} ({$m->title}). Add courts, widen the daily hours or extend the end date."]);
    }
}
