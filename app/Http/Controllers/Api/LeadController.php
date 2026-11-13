<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientEvent;
use App\Models\Lead;
use App\Models\Trial;
use App\Services\Hub;
use App\Services\Messenger;
use App\Services\Scheduling;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class LeadController extends Controller
{
    public const SOURCES = ['web', 'facebook', 'instagram', 'whatsapp', 'walk_in', 'phone', 'referral', 'manual'];

    public function index(Request $request)
    {
        return Lead::query()->inBranch(null, true)->with('branch:id,code,name')
            ->with(['trials' => fn ($q) => $q->latest('scheduled_at')->with('coach:id,name', 'court:id,code,name')])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->source, fn ($q, $s) => $q->where('source', $s))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('first_name', 'like', "%$s%")
                ->orWhere('last_name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('participant_name', 'like', "%$s%")))
            ->latest()->paginate($request->integer('per_page', 25));
    }

    public function show(Lead $lead)
    {
        return $lead->load('trials.coach:id,name', 'trials.court:id,name', 'trials.team:id,name', 'clients', 'family')
            ->setAttribute('events', ClientEvent::where('lead_id', $lead->id)->with('actor:id,name')->latest('occurred_at')->get());
    }

    public function store(Request $request)
    {
        $lead = Lead::create($this->validated($request) + ['created_by' => $request->user()?->id]);
        Hub::journey(null, 'lead', "Lead captured via {$lead->source}", [], null, $lead->id);

        return response()->json($lead, 201);
    }

    /** Public, unauthenticated web / social form capture. */
    public function capture(Request $request)
    {
        $data = $this->validated($request);
        $data['source'] = in_array($request->source, ['web', 'facebook', 'instagram', 'whatsapp']) ? $request->source : 'web';
        $lead = Lead::create($data);
        Hub::journey(null, 'lead', "Lead captured via {$lead->source} form", [], null, $lead->id);
        Hub::alert('lead', "New {$lead->source} lead: {$lead->full_name}", $lead->participant_name ? "Trial for {$lead->participant_name}" : null, '/leads', null, 'reception');

        return response()->json(['message' => 'Thank you! Our team will contact you to schedule a trial.'], 201);
    }

    public function update(Request $request, Lead $lead)
    {
        $lead->update($this->validated($request, true));

        return $lead;
    }

    public function destroy(Lead $lead)
    {
        $lead->update(['status' => 'lost']);
        Hub::journey(null, 'lead', 'Lead marked as lost', [], null, $lead->id);

        return $lead;
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes|' : '';

        return $request->validate([
            'first_name' => $r.'required|string|max:80',
            'last_name' => 'nullable|string|max:80',
            'phone' => $r.'required|string|max:30',
            'email' => 'nullable|email',
            'location_id' => 'nullable|exists:locations,id',
            'participant_name' => 'nullable|string|max:120',
            'participant_dob' => 'nullable|date',
            'source' => 'sometimes|in:'.implode(',', self::SOURCES),
            'status' => 'sometimes|in:new,trial_scheduled,trial_approved,trial_rejected,converted,lost',
            'notes' => 'nullable|string',
        ]);
    }

    // ---- Trials -------------------------------------------------------------

    public function trials(Request $request)
    {
        return Trial::with('lead', 'coach:id,name', 'court:id,code,name', 'team:id,name')->inBranch()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->date, fn ($q, $d) => $q->whereDate('scheduled_at', $d))
            ->when($request->coach_id, fn ($q, $c) => $q->where('coach_id', $c))
            ->orderByDesc('scheduled_at')->paginate($request->integer('per_page', 25));
    }

    public function scheduleTrial(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'scheduled_at' => 'required|date',
            'duration_min' => 'integer|min:15|max:240',
            'court_id' => 'nullable|exists:courts,id',
            'coach_id' => 'nullable|exists:users,id',
            'team_id' => 'nullable|exists:teams,id',
        ]);
        $start = Carbon::parse($data['scheduled_at']);
        Scheduling::assertFree($data['court_id'] ?? null, $start, $start->copy()->addMinutes($data['duration_min'] ?? 60));

        $trial = $lead->trials()->create($data + ['created_by' => $request->user()->id, 'status' => 'scheduled']);
        $lead->update(['status' => 'trial_scheduled']);

        Messenger::notify($lead, 'trial_scheduled', 'Your trial session is booked',
            "Hi {$lead->first_name}, the trial session".($lead->participant_name ? " for {$lead->participant_name}" : '').' at '.\App\Services\Tenant::name().' is booked for '.$start->format('l d M, H:i').'. See you on court!');
        $trial->update(['reminder_sent_at' => now()]);
        Hub::journey(null, 'trial', 'Trial scheduled for '.$start->format('d M Y H:i'), ['trial_id' => $trial->id], null, $lead->id);

        return response()->json($trial->load('coach:id,name', 'court:id,code,name'), 201);
    }

    /** Coach evaluation (rating 0-10, notes, Approved / Rejected), reschedule or cancel. */
    public function updateTrial(Request $request, Trial $trial)
    {
        $data = $request->validate([
            'scheduled_at' => 'sometimes|date',
            'duration_min' => 'sometimes|integer|min:15|max:240',
            'court_id' => 'sometimes|nullable|exists:courts,id',
            'coach_id' => 'sometimes|nullable|exists:users,id',
            'team_id' => 'sometimes|nullable|exists:teams,id',
            'rating' => 'sometimes|nullable|integer|min:0|max:10',
            'coach_notes' => 'sometimes|nullable|string',
            'status' => 'sometimes|in:scheduled,approved,rejected,canceled,no_show',
        ]);
        if (in_array($data['status'] ?? null, ['approved', 'rejected']) && ! isset($data['rating']) && $trial->rating === null) {
            throw ValidationException::withMessages(['rating' => 'Please rate the trial (0–10) before approving or rejecting it.']);
        }

        $rescheduled = isset($data['scheduled_at']) && ! Carbon::parse($data['scheduled_at'])->equalTo($trial->scheduled_at);
        if ($rescheduled || isset($data['court_id'])) {
            $start = Carbon::parse($data['scheduled_at'] ?? $trial->scheduled_at);
            Scheduling::assertFree($data['court_id'] ?? $trial->court_id, $start, $start->copy()->addMinutes($data['duration_min'] ?? $trial->duration_min), ['trial' => $trial->id]);
        }
        if ($rescheduled) {
            $data['day_before_reminder_sent_at'] = null;
        }

        $trial->update($data);
        $lead = $trial->lead;

        if (isset($data['status'])) {
            match ($data['status']) {
                'approved' => $lead->update(['status' => 'trial_approved']),
                'rejected' => $lead->update(['status' => 'trial_rejected']),
                default => null,
            };
            if (in_array($data['status'], ['approved', 'rejected'])) {
                $trial->update(['evaluated_at' => now()]);
                Hub::journey(null, 'trial', "Trial {$data['status']} with rating {$trial->rating}/10", ['trial_id' => $trial->id], null, $lead->id);
                if ($data['status'] === 'approved') {
                    Hub::alert('conversion', "Trial approved: {$lead->full_name}", 'Complete the full registration to convert this lead.', "/leads/{$lead->id}", null, 'reception');
                }
            }
        }
        if ($rescheduled) {
            Messenger::notify($lead, 'trial_rescheduled', 'Your trial was rescheduled', "Hi {$lead->first_name}, your trial has been moved to ".$trial->scheduled_at->format('l d M, H:i').'.');
            Hub::journey(null, 'trial', 'Trial rescheduled to '.$trial->scheduled_at->format('d M Y H:i'), [], null, $lead->id);
        }

        return $trial->load('lead', 'coach:id,name', 'court:id,code,name', 'team:id,name');
    }
}
