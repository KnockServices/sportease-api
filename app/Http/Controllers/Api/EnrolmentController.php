<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FollowUpTask;
use App\Models\Package;
use App\Models\PackageSession;
use App\Models\Registration;
use App\Models\Team;
use App\Models\WaitingListEntry;
use App\Services\Billing;
use App\Services\Hub;
use App\Services\Messenger;
use App\Services\WaitingList;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Team enrolment (multiple packages on one invoice) and the FIFO waiting list. */
class EnrolmentController extends Controller
{
    public function index(Request $request)
    {
        return Registration::with('client:id,code,first_name,last_name,family_id', 'package:id,name,type', 'team:id,name', 'coach:id,name', 'invoice:id,number,status,total,paid_amount,due_date')
            ->select('registrations.*')->selectSub(self::teamAttendanceSql(), 'attended_count')->inBranch()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->type, fn ($q, $t) => $t === 'sessions' ? $q->whereNotNull('sessions_total') : $q->whereNull('sessions_total'))
            ->when($request->team_id, fn ($q, $t) => $q->where('team_id', $t))
            ->when($request->client_id, fn ($q, $c) => $q->where('client_id', $c))
            ->latest()->paginate($request->integer('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'items' => 'required|array|min:1',
            'items.*.package_id' => 'required|exists:packages,id',
            'items.*.team_id' => 'nullable|exists:teams,id',
            'items.*.coach_id' => 'nullable|exists:users,id',
            'items.*.preferred_schedule' => 'nullable|string|max:255',
            'items.*.start_date' => 'nullable|date',
            'items.*.price' => 'nullable|numeric|min:0',
            'renewed_from_id' => 'nullable|exists:registrations,id',
        ]);
        $client = Client::with('family')->findOrFail($data['client_id']);
        Billing::guard($client->family, 'Registration');

        // Capacity check before anything is written.
        foreach ($data['items'] as $i => $item) {
            $package = Package::find($item['package_id']);
            $teamId = $package->isSessionBased() ? null : ($item['team_id'] ?? $package->team_id);
            if ($teamId) {
                $team = Team::find($teamId);
                $hasOffer = WaitingListEntry::where('team_id', $teamId)->where('client_id', $client->id)->whereIn('status', ['offered', 'accepted'])->exists();
                if (! $hasOffer && WaitingList::openSpots($team) <= 0) {
                    throw ValidationException::withMessages(["items.$i.team_id" => "{$team->name} is full ({$team->capacity}). Add the client to the waiting list instead."])
                        ->status(409);
                }
            }
        }

        $invoice = DB::transaction(function () use ($data, $client) {
            $registrations = [];
            $lines = [];
            foreach ($data['items'] as $item) {
                $package = Package::find($item['package_id']);
                $teamId = $package->isSessionBased() ? null : ($item['team_id'] ?? $package->team_id);
                $team = $teamId ? Team::find($teamId) : null;
                $start = Carbon::parse($item['start_date'] ?? today());
                $sessions = $package->isSessionBased() ? $package->sessions_count : null;
                $reg = Registration::create([
                    'client_id' => $client->id,
                    'package_id' => $package->id,
                    'team_id' => $teamId,
                    'coach_id' => $item['coach_id'] ?? $team?->coach_id,
                    'preferred_schedule' => $item['preferred_schedule'] ?? null,
                    'price' => $item['price'] ?? $package->price,
                    'start_date' => $start,
                    'end_date' => $start->copy()->addMonths($package->duration_months)->subDay(),
                    'sessions_total' => $sessions,
                    'status' => 'active',
                    'renewed_from_id' => $data['renewed_from_id'] ?? null,
                    'created_by' => auth()->id(),
                ]);
                $registrations[] = $reg;
                $lines[] = ['description' => "{$package->name}".($team ? " — {$team->name}" : '').($sessions ? " ({$sessions} sessions)" : '')." for {$client->full_name} ({$start->format('d M')} – {$reg->end_date->format('d M Y')})", 'unit_price' => $reg->price, 'itemable' => $reg];

                if ($teamId) {
                    WaitingListEntry::where('team_id', $teamId)->where('client_id', $client->id)->whereIn('status', ['waiting', 'offered'])
                        ->update(['status' => 'accepted', 'responded_at' => now()]);
                }
                Hub::journey($client->id, 'enrolment', "Enrolled in {$package->name}".($team ? " ({$team->name})" : ''), ['registration_id' => $reg->id], $client->family_id);
            }
            if (! empty($data['renewed_from_id'])) {
                Registration::whereKey($data['renewed_from_id'])->update(['status' => 'ended']);
            }

            $invoice = Billing::invoice($client->family, $lines, 'registration', $data['discount_percent'] ?? null, (int) Hub::setting('billing.due_days', 7));
            foreach ($registrations as $reg) {
                $reg->update(['invoice_id' => $invoice->id]);
            }

            return $invoice;
        });

        Messenger::notify($client, 'enrolment', 'Welcome to the team', "{$client->full_name} is now enrolled. Invoice {$invoice->number}: \${$invoice->total} due ".$invoice->due_date->format('d M Y').'.');

        return response()->json($invoice->load('items'), 201);
    }

    public function cancel(Request $request, Registration $registration)
    {
        $request->validate(['reason' => 'nullable|string']);
        $registration->update(['status' => 'canceled']);
        Hub::journey($registration->client_id, 'enrolment', 'Registration canceled'.($request->reason ? ": {$request->reason}" : ''), [], $registration->client->family_id);
        if ($registration->team) {
            WaitingList::process($registration->team);
        }

        return $registration;
    }

    // ---- Session-based (non-team) packages ---------------------------------

    /** Attended team sessions for a team registration within its period (SQL subquery). */
    public static function teamAttendanceSql()
    {
        return \App\Models\Attendance::query()->selectRaw('count(*)')
            ->join('team_sessions', 'team_sessions.id', '=', 'attendances.team_session_id')
            ->whereColumn('attendances.client_id', 'registrations.client_id')
            ->whereColumn('team_sessions.team_id', 'registrations.team_id')
            ->whereIn('attendances.status', ['present', 'late'])
            ->whereRaw('date(team_sessions.starts_at) between date(registrations.start_date) and date(registrations.end_date)');
    }

    /** Active session-based registrations with what is left on each. */
    public function sessionPackages(Request $request)
    {
        return Registration::with('client:id,code,first_name,last_name,family_id', 'package:id,name,sessions_count,session_minutes', 'coach:id,name')
            ->whereNotNull('sessions_total')->inBranch()
            ->when($request->input('status', 'active'), fn ($q, $s) => $s === 'all' ? $q : $q->where('status', $s))
            ->when($request->coach_id, fn ($q, $c) => $q->where('coach_id', $c))
            ->when($request->user()->role === 'coach', fn ($q) => $q->where('coach_id', $request->user()->id))
            ->when($request->search, fn ($q, $term) => $q->whereHas('client', fn ($c) => $c->where(fn ($c) => $c->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")->orWhere('code', 'like', "%$term%"))))
            ->orderBy('end_date')->get()
            ->each(fn ($r) => $r->setAttribute('last_session_at', PackageSession::where('registration_id', $r->id)->max('held_at')));
    }

    public function sessions(Registration $registration)
    {
        return [
            'registration' => $registration->load('client:id,code,first_name,last_name', 'package:id,name,sessions_count,session_minutes', 'coach:id,name'),
            'sessions' => $registration->packageSessions()->with('coach:id,name', 'marker:id,name')->get(),
        ];
    }

    public function logSession(Request $request, Registration $registration)
    {
        $data = $request->validate([
            'held_at' => 'required|date', 'duration_min' => 'nullable|integer|min:15|max:480',
            'status' => 'required|in:attended,no_show,excused', 'coach_id' => 'nullable|exists:users,id', 'note' => 'nullable|string|max:500',
        ]);
        if ($registration->sessions_total === null) {
            throw ValidationException::withMessages(['registration' => 'This is a team package; mark attendance on the team session instead.']);
        }
        if ($registration->status === 'canceled') {
            throw ValidationException::withMessages(['registration' => 'This registration is canceled.']);
        }
        $counted = in_array($data['status'], PackageSession::COUNTED);
        if ($counted && $registration->sessions_remaining <= 0) {
            throw ValidationException::withMessages(['status' => 'No sessions left on this package. Renew it to log more sessions.']);
        }
        $heldAt = Carbon::parse($data['held_at']);
        if ($counted && $heldAt->toDateString() > $registration->end_date->toDateString()) {
            throw ValidationException::withMessages(['held_at' => 'The package expired on '.$registration->end_date->format('d M Y').'.']);
        }

        $session = $registration->packageSessions()->create([
            'client_id' => $registration->client_id, 'coach_id' => $data['coach_id'] ?? $registration->coach_id, 'held_at' => $heldAt,
            'duration_min' => $data['duration_min'] ?? $registration->package->session_minutes ?? 60, 'status' => $data['status'],
            'note' => $data['note'] ?? null, 'marked_by' => $request->user()->id,
        ]);
        $this->recount($registration);

        $client = $registration->client;
        $left = $registration->sessions_remaining;
        $label = ['attended' => 'Attended', 'no_show' => 'No-show (counted)', 'excused' => 'Excused (not counted)'][$data['status']];
        Hub::journey($client->id, 'attendance', "{$label}: {$registration->package->name} session on ".$heldAt->format('d M H:i')." — {$left} of {$registration->sessions_total} left", ['registration_id' => $registration->id], $client->family_id);

        if ($counted && $left === 0) {
            Hub::alert('package', "{$client->full_name} used all sessions", "{$registration->package->name} is complete — offer a renewal.", "/clients/{$client->id}", null, 'reception');
            Messenger::notify($client, 'package_complete', 'Package completed', "{$client->full_name} has used all {$registration->sessions_total} sessions of {$registration->package->name}. Contact us to renew.");
        } elseif ($counted && $left === 1) {
            Messenger::notify($client, 'package_low', 'One session left', "{$client->full_name} has 1 session left on {$registration->package->name}.");
        }

        return response()->json(['session' => $session->load('coach:id,name'), 'registration' => $registration->fresh()], 201);
    }

    public function deleteSession(PackageSession $packageSession)
    {
        $registration = $packageSession->registration;
        $packageSession->delete();
        $this->recount($registration);
        Hub::journey($registration->client_id, 'attendance', "Session entry for {$registration->package->name} removed — {$registration->sessions_remaining} left", [], $registration->client->family_id);

        return $registration->fresh();
    }

    /** Recalculate the used counter; a fully used package ends, and re-opens if an entry is removed. */
    private function recount(Registration $registration): void
    {
        $used = PackageSession::where('registration_id', $registration->id)->whereIn('status', PackageSession::COUNTED)->count();
        $status = $registration->status;
        if ($used >= $registration->sessions_total && $status === 'active') {
            $status = 'ended';
        } elseif ($used < $registration->sessions_total && $status === 'ended' && $registration->end_date->gte(today())
            && ! Registration::where('renewed_from_id', $registration->id)->exists()) {
            $status = 'active';
        }
        $registration->update(['sessions_used' => $used, 'status' => $status]);
    }

    // ---- Waiting list -------------------------------------------------------

    public function waitingList(Request $request)
    {
        return WaitingListEntry::with('team:id,name,capacity', 'client:id,code,first_name,last_name,family_id')->inBranch()
            ->when($request->team_id, fn ($q, $t) => $q->where('team_id', $t))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', ['waiting', 'offered']))
            ->orderBy('team_id')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($e) => $e->setAttribute('position', WaitingList::position($e)));
    }

    public function joinWaitingList(Request $request)
    {
        $data = $request->validate(['team_id' => 'required|exists:teams,id', 'client_id' => 'required|exists:clients,id', 'notes' => 'nullable|string']);
        if (WaitingListEntry::where($data)->whereIn('status', ['waiting', 'offered'])->exists()) {
            throw ValidationException::withMessages(['client_id' => 'This client is already on the waiting list for this team.']);
        }
        $entry = WaitingListEntry::create($data + ['status' => 'waiting']);
        $team = $entry->team;
        Hub::journey($entry->client_id, 'waitlist', "Joined waiting list for {$team->name}", [], $entry->client->family_id);
        WaitingList::process($team);

        return response()->json($entry->fresh()->setAttribute('position', WaitingList::position($entry->fresh())), 201);
    }

    public function respond(Request $request, WaitingListEntry $entry)
    {
        $data = $request->validate(['response' => 'required|in:accept,decline,remove']);
        if ($data['response'] === 'accept') {
            if ($entry->status !== 'offered' || $entry->offer_expires_at?->isPast()) {
                throw ValidationException::withMessages(['response' => 'There is no active offer for this entry.']);
            }
            $entry->update(['status' => 'accepted', 'responded_at' => now()]);
            FollowUpTask::create([
                'type' => 'waiting_list', 'title' => "Complete enrolment: {$entry->client->full_name} → {$entry->team->name}",
                'client_id' => $entry->client_id, 'due_date' => today()->addDay(), 'created_by' => auth()->id(),
            ]);
        } else {
            $entry->update(['status' => $data['response'] === 'decline' ? 'declined' : 'expired', 'responded_at' => now()]);
            WaitingList::process($entry->team);
        }
        Hub::journey($entry->client_id, 'waitlist', "Waiting list {$data['response']} for {$entry->team->name}", [], $entry->client->family_id);

        return $entry;
    }
}
