<?php

namespace App\Services;

use App\Models\Court;
use App\Models\Reservation;
use App\Models\TeamSchedule;
use App\Models\TeamSession;
use App\Models\TournamentMatch;
use App\Models\Trial;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Sub-court aware availability and recurring team session generation.
 */
class Scheduling
{
    /**
     * Courts that physically overlap $courtId: itself, its parents (the full court) and all
     * of its sub-courts. Booking a half court therefore blocks the full court and its quarters,
     * but not the other half.
     *
     * @return array<int>
     */
    public static function overlappingCourtIds(int $courtId): array
    {
        $all = Court::get(['id', 'parent_id'])->keyBy('id');
        $ids = [$courtId];

        for ($c = $all[$courtId] ?? null; $c && $c->parent_id; $c = $all[$c->parent_id] ?? null) {
            $ids[] = $c->parent_id;
        }

        $queue = [$courtId];
        while ($queue) {
            $id = array_shift($queue);
            foreach ($all as $c) {
                if ($c->parent_id === $id) {
                    $ids[] = $c->id;
                    $queue[] = $c->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return array<int, array{type:string, id:int, title:string, starts_at:string, ends_at:string}> */
    public static function conflicts(int $courtId, CarbonInterface $start, CarbonInterface $end, array $ignore = []): array
    {
        $ids = self::overlappingCourtIds($courtId);
        $out = [];

        Reservation::whereIn('court_id', $ids)->where('status', 'confirmed')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when(isset($ignore['reservation']), fn ($q) => $q->where('id', '!=', $ignore['reservation']))
            ->with('court')->get()
            ->each(function ($r) use (&$out) {
                $out[] = ['type' => 'reservation', 'id' => $r->id, 'title' => "Reservation on {$r->court->label}", 'starts_at' => $r->starts_at->toIso8601String(), 'ends_at' => $r->ends_at->toIso8601String()];
            });

        TeamSession::whereIn('court_id', $ids)->where('status', 'scheduled')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when(isset($ignore['session']), fn ($q) => $q->where('id', '!=', $ignore['session']))
            ->with('team', 'court')->get()
            ->each(function ($s) use (&$out) {
                $out[] = ['type' => 'session', 'id' => $s->id, 'title' => "{$s->team->name} training on {$s->court->label}", 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String()];
            });

        Trial::whereIn('court_id', $ids)->where('status', 'scheduled')
            ->where('scheduled_at', '<', $end)
            ->when(isset($ignore['trial']), fn ($q) => $q->where('id', '!=', $ignore['trial']))
            ->with('court', 'lead')->get()
            ->filter(fn ($t) => $t->scheduled_at->copy()->addMinutes($t->duration_min)->gt($start))
            ->each(function ($t) use (&$out) {
                $out[] = ['type' => 'trial', 'id' => $t->id, 'title' => "Trial for {$t->lead->full_name}", 'starts_at' => $t->scheduled_at->toIso8601String(), 'ends_at' => $t->scheduled_at->copy()->addMinutes($t->duration_min)->toIso8601String()];
            });

        TournamentMatch::whereIn('court_id', $ids)->where('status', 'scheduled')->whereNotNull('starts_at')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when(isset($ignore['tournament_match']), fn ($q) => $q->where('id', '!=', $ignore['tournament_match']))
            ->with('tournament:id,name', 'court', 'home:id,name', 'away:id,name')->get()
            ->each(function ($m) use (&$out) {
                $out[] = ['type' => 'tournament_match', 'id' => $m->id, 'title' => "{$m->tournament->name}: {$m->title} on {$m->court->label}", 'starts_at' => $m->starts_at->toIso8601String(), 'ends_at' => $m->ends_at->toIso8601String()];
            });

        return $out;
    }

    public static function assertFree(?int $courtId, CarbonInterface $start, CarbonInterface $end, array $ignore = []): void
    {
        if (! $courtId) {
            return;
        }
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => 'End time must be after the start time.']);
        }
        $conflicts = self::conflicts($courtId, $start, $end, $ignore);
        if ($conflicts) {
            $c = $conflicts[0];
            throw ValidationException::withMessages([
                'court_id' => "Court is not available: overlaps {$c['title']} (".Carbon::parse($c['starts_at'])->format('D d M H:i').'–'.Carbon::parse($c['ends_at'])->format('H:i').').',
            ]);
        }
    }

    /** Materialise recurring team schedules into dated sessions for the next $days days. */
    public static function generateSessions(int $days = 28, ?int $teamId = null): int
    {
        $created = 0;
        $schedules = TeamSchedule::with('team')->when($teamId, fn ($q) => $q->where('team_id', $teamId))->get();

        foreach ($schedules as $schedule) {
            if (! $schedule->team?->active) {
                continue;
            }
            for ($d = 0; $d < $days; $d++) {
                $date = today()->addDays($d);
                if ($date->dayOfWeek !== (int) $schedule->weekday) {
                    continue;
                }
                $start = Carbon::parse($date->toDateString().' '.$schedule->start_time);
                $end = Carbon::parse($date->toDateString().' '.$schedule->end_time);

                $session = TeamSession::firstOrCreate(
                    ['team_schedule_id' => $schedule->id, 'original_starts_at' => $start],
                    ['team_id' => $schedule->team_id, 'court_id' => $schedule->court_id, 'coach_id' => $schedule->team->coach_id, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'scheduled'],
                );
                $created += $session->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $created;
    }
}
