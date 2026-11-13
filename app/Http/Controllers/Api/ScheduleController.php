<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Court;
use App\Models\Family;
use App\Models\Registration;
use App\Models\Reservation;
use App\Models\TeamSession;
use App\Models\TournamentMatch;
use App\Models\Trial;
use App\Models\User;
use App\Services\Billing;
use App\Services\Hub;
use App\Services\Messenger;
use App\Services\Pricing;
use App\Services\Scheduling;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Master calendar, court reservations and team session changes. */
class ScheduleController extends Controller
{
    /**
     * Unified event feed. types: reservation,registration,training (registration = trials & first days).
     * include: canceled,removed,rescheduled toggles.
     */
    public function events(Request $request)
    {
        $request->validate(['start' => 'required|date', 'end' => 'required|date']);
        $start = Carbon::parse($request->start);
        $end = Carbon::parse($request->end);
        $types = array_filter(explode(',', $request->input('types', 'reservation,registration,training,tournament')));
        $statuses = array_merge(['scheduled', 'confirmed', 'approved', 'rejected', 'no_show'], array_filter(explode(',', (string) $request->input('include', ''))));
        $courtIds = $request->court_id ? Scheduling::overlappingCourtIds((int) $request->court_id) : null;
        $events = [];

        if (in_array('training', $types)) {
            TeamSession::with('team:id,name,color', 'court:id,code,name', 'coach:id,name', 'substituteCoach:id,name')
                ->whereBetween('starts_at', [$start, $end])->whereIn('status', $statuses)->inBranch()
                ->when($courtIds, fn ($q) => $q->whereIn('court_id', $courtIds))
                ->when($request->coach_id, fn ($q, $c) => $q->where(fn ($q) => $q->where('coach_id', $c)->orWhere('substitute_coach_id', $c)))
                ->get()->each(function ($s) use (&$events) {
                    $events[] = [
                        'id' => "session-{$s->id}", 'type' => 'training', 'record_id' => $s->id,
                        'title' => $s->team->name, 'start' => $s->starts_at->toIso8601String(), 'end' => $s->ends_at->toIso8601String(),
                        'status' => $s->status, 'color' => $s->team->color, 'court' => $s->court?->label,
                        'coach' => $s->substituteCoach?->name ?? $s->coach?->name, 'substitute' => (bool) $s->substitute_coach_id,
                        'original_start' => $s->original_starts_at?->toIso8601String(),
                    ];
                });
        }

        if (in_array('reservation', $types)) {
            Reservation::with('court:id,code,name,kind,parent_id', 'court.parent:id,code,name', 'client:id,first_name,last_name', 'family:id,name')
                ->whereBetween('starts_at', [$start, $end])->whereIn('status', $statuses)->inBranch()
                ->when($courtIds, fn ($q) => $q->whereIn('court_id', $courtIds))
                ->get()->each(function ($r) use (&$events) {
                    $who = $r->client?->full_name ?? $r->contact_name ?? $r->family?->name ?? 'Walk-in';
                    $events[] = [
                        'id' => "reservation-{$r->id}", 'type' => 'reservation', 'record_id' => $r->id,
                        'title' => "{$r->court->label} · {$who}", 'start' => $r->starts_at->toIso8601String(), 'end' => $r->ends_at->toIso8601String(),
                        'status' => $r->status === 'confirmed' ? 'scheduled' : $r->status, 'color' => '#0ea5e9', 'court' => $r->court->label,
                    ];
                });
        }

        if (in_array('tournament', $types)) {
            TournamentMatch::with('tournament:id,name', 'home:id,name', 'away:id,name', 'court:id,code,name,kind,parent_id', 'court.parent:id,code,name')
                ->whereNotNull('starts_at')->whereBetween('starts_at', [$start, $end])->inBranch()
                ->whereIn('status', array_merge(['scheduled', 'completed'], in_array('canceled', $statuses) ? ['canceled'] : []))
                ->when($courtIds, fn ($q) => $q->whereIn('court_id', $courtIds))
                ->get()->each(function ($m) use (&$events) {
                    $score = $m->status === 'completed' ? " ({$m->home_score}–{$m->away_score})" : '';
                    $events[] = [
                        'id' => "match-{$m->id}", 'type' => 'tournament', 'record_id' => $m->id, 'tournament_id' => $m->tournament_id,
                        'title' => "{$m->tournament->name} · {$m->title}{$score}", 'start' => $m->starts_at->toIso8601String(), 'end' => $m->ends_at->toIso8601String(),
                        'status' => $m->status === 'completed' ? 'done' : $m->status, 'color' => '#eab308', 'court' => $m->court?->label, 'round' => $m->round_name,
                    ];
                });
        }

        if (in_array('registration', $types)) {
            $trialStatuses = array_map(fn ($s) => $s === 'removed' ? 'no_show' : $s, $statuses);
            Trial::with('lead:id,first_name,last_name,participant_name', 'court:id,code,name', 'coach:id,name')
                ->whereBetween('scheduled_at', [$start, $end])->whereIn('status', $trialStatuses)->inBranch()
                ->when($courtIds, fn ($q) => $q->whereIn('court_id', $courtIds))
                ->when($request->coach_id, fn ($q, $c) => $q->where('coach_id', $c))
                ->get()->each(function ($t) use (&$events) {
                    $events[] = [
                        'id' => "trial-{$t->id}", 'type' => 'registration', 'subtype' => 'trial', 'record_id' => $t->id, 'lead_id' => $t->lead_id,
                        'title' => 'Trial · '.($t->lead->participant_name ?: $t->lead->full_name), 'start' => $t->scheduled_at->toIso8601String(),
                        'end' => $t->scheduled_at->copy()->addMinutes($t->duration_min)->toIso8601String(),
                        'status' => $t->status, 'color' => '#a855f7', 'court' => $t->court?->label, 'coach' => $t->coach?->name,
                    ];
                });
            Registration::with('client:id,first_name,last_name', 'team:id,name')->whereDate('start_date', '>=', $start)->whereDate('start_date', '<=', $end)
                ->where('status', 'active')->inBranch()->get()->each(function ($r) use (&$events) {
                    $events[] = [
                        'id' => "registration-{$r->id}", 'type' => 'registration', 'subtype' => 'first_day', 'record_id' => $r->id,
                        'title' => "First day · {$r->client->full_name}".($r->team ? " ({$r->team->name})" : ''),
                        'start' => $r->start_date->toDateString(), 'allDay' => true, 'status' => 'scheduled', 'color' => '#22c55e',
                    ];
                });
        }

        return $events;
    }

    public function availability(Request $request)
    {
        $data = $request->validate(['court_id' => 'required|exists:courts,id', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at']);

        return ['conflicts' => Scheduling::conflicts((int) $data['court_id'], Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']), $request->only('reservation', 'session', 'trial'))];
    }

    public function generate(Request $request)
    {
        return ['created' => Scheduling::generateSessions($request->integer('days', 28))];
    }

    // ---- Team sessions: cancel / reschedule / substitute -------------------

    public function updateSession(Request $request, TeamSession $session)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:scheduled,canceled,removed',
            'starts_at' => 'sometimes|date', 'ends_at' => 'sometimes|date',
            'court_id' => 'sometimes|nullable|exists:courts,id',
            'substitute_coach_id' => 'sometimes|nullable|exists:users,id',
            'notes' => 'nullable|string', 'notify' => 'boolean',
        ]);
        $messages = [];

        if (isset($data['starts_at']) && ! Carbon::parse($data['starts_at'])->equalTo($session->starts_at)) {
            $newStart = Carbon::parse($data['starts_at']);
            $newEnd = Carbon::parse($data['ends_at'] ?? $newStart->copy()->addMinutes($session->starts_at->diffInMinutes($session->ends_at)));
            Scheduling::assertFree($data['court_id'] ?? $session->court_id, $newStart, $newEnd, ['session' => $session->id]);

            // Keep the original visible as "rescheduled" and create the moved session.
            $moved = $session->replicate(['team_schedule_id']);
            $moved->fill(['starts_at' => $newStart, 'ends_at' => $newEnd, 'court_id' => $data['court_id'] ?? $session->court_id, 'status' => 'scheduled',
                'original_starts_at' => $session->starts_at, 'notes' => $data['notes'] ?? null, 'substitute_coach_id' => $data['substitute_coach_id'] ?? $session->substitute_coach_id])->save();
            $session->update(['status' => 'rescheduled', 'notes' => 'Moved to '.$newStart->format('D d M H:i')]);
            $messages[] = "{$session->team->name} training on ".$session->starts_at->format('D d M H:i').' has been rescheduled to '.$newStart->format('D d M H:i').'.';
            $result = $moved;
        } else {
            if (isset($data['court_id']) && $data['court_id'] != $session->court_id) {
                Scheduling::assertFree($data['court_id'], $session->starts_at, $session->ends_at, ['session' => $session->id]);
            }
            $session->update(collect($data)->except('notify', 'starts_at', 'ends_at')->all());
            if (($data['status'] ?? null) === 'canceled') {
                $messages[] = "{$session->team->name} training on ".$session->starts_at->format('D d M H:i').' is canceled.';
            }
            $result = $session;
        }

        if (array_key_exists('substitute_coach_id', $data) && $data['substitute_coach_id']) {
            $coach = User::find($data['substitute_coach_id']);
            $messages[] = "Coach update: {$coach->name} will lead {$session->team->name} on ".$result->starts_at->format('D d M H:i').'.';
            Hub::alert('substitution', "You are substituting for {$session->team->name}", $result->starts_at->format('D d M H:i'), '/calendar', $coach->id);
        }

        if ($messages && ($data['notify'] ?? true)) {
            $clientIds = Registration::where('team_id', $session->team_id)->where('status', 'active')->pluck('client_id');
            foreach (Client::whereIn('id', $clientIds)->get() as $client) {
                Messenger::notify($client, 'session_change', 'Training schedule update', implode(' ', $messages));
                Hub::journey($client->id, 'schedule', implode(' ', $messages), [], $client->family_id);
            }
        }

        return $result->load('team:id,name', 'court:id,code,name', 'coach:id,name', 'substituteCoach:id,name');
    }

    // ---- Reservations -------------------------------------------------------

    public function reservations(Request $request)
    {
        return Reservation::with('court:id,code,name,kind,parent_id', 'court.parent:id,code,name', 'client:id,first_name,last_name,code', 'family:id,code,name', 'invoice:id,number,status')->inBranch()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->date, fn ($q, $d) => $q->whereDate('starts_at', $d))
            ->orderByDesc('starts_at')->paginate($request->integer('per_page', 25));
    }

    /** Live price quote from the pricing engine, with the family discount applied. */
    public function quote(Request $request)
    {
        $data = $request->validate([
            'court_id' => 'required|exists:courts,id', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'client_id' => 'nullable|exists:clients,id', 'discount_percent' => 'nullable|numeric|min:0|max:100', 'price' => 'nullable|numeric|min:0',
        ]);
        $court = Court::with('rates')->find($data['court_id']);
        $family = ! empty($data['client_id']) ? Family::find(Client::find($data['client_id'])->family_id) : null;
        $minutes = (int) Carbon::parse($data['starts_at'])->diffInMinutes(Carbon::parse($data['ends_at']));

        return Pricing::quote($court, $minutes, $family, isset($data['discount_percent']) ? (float) $data['discount_percent'] : null, isset($data['price']) ? (float) $data['price'] : null)
            + ['court' => ['id' => $court->id, 'code' => $court->code, 'name' => $court->name, 'rates' => $court->rates]];
    }

    public function storeReservation(Request $request)
    {
        $data = $request->validate([
            'court_id' => 'required|exists:courts,id', 'client_id' => 'nullable|exists:clients,id',
            'contact_name' => 'required_without:client_id|nullable|string',
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'price' => 'nullable|numeric|min:0', 'discount_percent' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string', 'invoice' => 'boolean',
        ]);
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        $client = isset($data['client_id']) ? Client::find($data['client_id']) : null;
        $family = $client ? Family::find($client->family_id) : null;

        Billing::guard($family, 'Court reservation');
        Scheduling::assertFree((int) $data['court_id'], $start, $end);

        $court = Court::find($data['court_id']);
        $minutes = (int) $start->diffInMinutes($end);
        $q = Pricing::quote($court, $minutes, $family, isset($data['discount_percent']) ? (float) $data['discount_percent'] : null, isset($data['price']) ? (float) $data['price'] : null);
        $note = $q['price_overridden'] ? "Manual price (engine: \${$q['engine_price']})" : $q['note'];

        $reservation = Reservation::create([
            'court_id' => $court->id, 'client_id' => $client?->id, 'family_id' => $family?->id, 'contact_name' => $data['contact_name'] ?? null,
            'starts_at' => $start, 'ends_at' => $end, 'duration_min' => $minutes, 'price' => $q['price'], 'pricing_note' => $note,
            'discount_percent' => $family ? $q['discount_percent'] : 0, 'discount_amount' => $family ? $q['discount_amount'] : 0, 'total' => $family ? $q['total'] : $q['price'],
            'status' => 'confirmed', 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id,
        ]);

        if ($family && $q['price'] > 0 && ($data['invoice'] ?? true)) {
            $invoice = Billing::invoice($family, [['description' => "Court reservation {$court->code} {$court->name} ".$start->format('D d M H:i').'–'.$end->format('H:i')." ({$minutes} min · {$note})", 'unit_price' => $q['price'], 'itemable' => $reservation]],
                'reservation', $q['discount_percent'], 0);
            $reservation->update(['invoice_id' => $invoice->id]);
        }
        if ($client) {
            Hub::journey($client->id, 'reservation', "Booked {$court->name} for ".$start->format('d M H:i')." — \${$reservation->total}".($reservation->discount_percent > 0 ? " after {$reservation->discount_percent}% family discount" : ''), ['reservation_id' => $reservation->id], $family->id);
            Messenger::notify($client, 'reservation', 'Court booking confirmed', "Your booking of {$court->name} on ".$start->format('l d M, H:i').'–'.$end->format('H:i')." is confirmed. Total \${$reservation->total}.");
        }

        return response()->json($reservation->load('court', 'invoice'), 201);
    }

    public function updateReservation(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['status' => 'sometimes|in:confirmed,canceled,removed', 'starts_at' => 'sometimes|date', 'ends_at' => 'sometimes|date', 'court_id' => 'sometimes|exists:courts,id', 'notes' => 'nullable|string']);

        if (isset($data['starts_at']) || isset($data['court_id'])) {
            $start = Carbon::parse($data['starts_at'] ?? $reservation->starts_at);
            $end = Carbon::parse($data['ends_at'] ?? $start->copy()->addMinutes($reservation->starts_at->diffInMinutes($reservation->ends_at)));
            Scheduling::assertFree((int) ($data['court_id'] ?? $reservation->court_id), $start, $end, ['reservation' => $reservation->id]);

            $moved = $reservation->replicate();
            $moved->fill(['starts_at' => $start, 'ends_at' => $end, 'court_id' => $data['court_id'] ?? $reservation->court_id, 'original_starts_at' => $reservation->starts_at, 'status' => 'confirmed'])->save();
            $reservation->update(['status' => 'rescheduled', 'invoice_id' => null, 'notes' => 'Moved to '.$start->format('D d M H:i')]);
            if ($reservation->client) {
                Messenger::notify($reservation->client, 'reservation_rescheduled', 'Court booking rescheduled', 'Your court booking was moved to '.$start->format('l d M, H:i').'.');
            }

            return $moved->load('court');
        }

        $reservation->update($data);
        if (in_array($data['status'] ?? null, ['canceled', 'removed']) && $reservation->invoice && $reservation->invoice->paid_amount == 0) {
            Billing::voidInvoice($reservation->invoice, "Reservation {$data['status']}");
        }
        if (($data['status'] ?? null) === 'canceled' && $reservation->client) {
            Messenger::notify($reservation->client, 'reservation_canceled', 'Court booking canceled', "Your booking of {$reservation->court->label} on ".$reservation->starts_at->format('d M H:i').' was canceled.');
            Hub::journey($reservation->client_id, 'reservation', 'Reservation canceled', [], $reservation->family_id);
        }

        return $reservation->load('court');
    }
}
