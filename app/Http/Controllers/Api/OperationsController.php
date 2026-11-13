<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\FollowUpTask;
use App\Models\Location;
use App\Models\Registration;
use App\Models\StaffAttendance;
use App\Models\TeamSession;
use App\Services\Billing;
use App\Services\Hub;
use Illuminate\Http\Request;

/** Student attendance, follow-up tasks and on-site HR geofencing. */
class OperationsController extends Controller
{
    public function sessions(Request $request)
    {
        $date = $request->input('date', today()->toDateString());

        return TeamSession::with('team:id,name,color', 'court:id,code,name', 'coach:id,name', 'substituteCoach:id,name')
            ->withCount('attendances')->inBranch()
            ->whereDate('starts_at', $date)->whereIn('status', ['scheduled'])
            ->when($request->user()->role === 'coach', fn ($q) => $q->where(fn ($q) => $q->where('coach_id', $request->user()->id)->orWhere('substitute_coach_id', $request->user()->id)))
            ->orderBy('starts_at')->get();
    }

    /** Attendance sheet: roster from active registrations, with outstanding-balance alerts for the instructor. */
    public function sheet(TeamSession $session)
    {
        $date = $session->starts_at->toDateString();
        $clientIds = Registration::where('team_id', $session->team_id)->whereIn('status', ['active'])
            ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->pluck('client_id')->unique();
        $marks = Attendance::where('team_session_id', $session->id)->get()->keyBy('client_id');

        $roster = Client::whereIn('id', $clientIds)->orderBy('first_name')->get()->map(function ($c) use ($marks) {
            $billing = Billing::status($c->family_id);

            return [
                'client_id' => $c->id, 'code' => $c->code, 'name' => $c->full_name, 'status' => $marks[$c->id]->status ?? null, 'note' => $marks[$c->id]->note ?? null,
                'outstanding' => $billing['outstanding'], 'restricted' => $billing['restricted'],
                'alert' => $billing['outstanding'] > 0 ? ($billing['restricted'] ? "Overdue \${$billing['overdue']} — account restricted" : "Outstanding balance \${$billing['outstanding']}") : null,
            ];
        });

        return ['session' => $session->load('team:id,name', 'court:id,code,name', 'coach:id,name', 'substituteCoach:id,name'), 'roster' => $roster];
    }

    public function mark(Request $request, TeamSession $session)
    {
        $data = $request->validate(['marks' => 'required|array', 'marks.*.client_id' => 'required|exists:clients,id', 'marks.*.status' => 'required|in:present,absent,late,excused', 'marks.*.note' => 'nullable|string|max:500']);
        $threshold = (int) Hub::setting('attendance.absence_threshold', 2);
        $tasks = 0;

        foreach ($data['marks'] as $m) {
            $att = Attendance::updateOrCreate(['team_session_id' => $session->id, 'client_id' => $m['client_id']], ['status' => $m['status'], 'note' => $m['note'] ?? null, 'marked_by' => $request->user()->id]);
            if ($m['status'] !== 'absent' || ! $att->wasChanged('status') && ! $att->wasRecentlyCreated) {
                continue;
            }
            $client = Client::find($m['client_id']);
            Hub::journey($client->id, 'attendance', "Absent from {$session->team->name} on ".$session->starts_at->format('d M'), [], $client->family_id);

            // Count absences since the last absence follow-up task was created for this client.
            $since = FollowUpTask::where('client_id', $client->id)->where('type', 'absence')->max('created_at');
            $absences = Attendance::where('client_id', $client->id)->where('status', 'absent')->when($since, fn ($q) => $q->where('updated_at', '>', $since))->count();
            if ($absences >= $threshold) {
                FollowUpTask::create([
                    'type' => 'absence', 'title' => "Follow up with parent: {$client->full_name} missed {$absences} sessions",
                    'description' => "Latest absence: {$session->team->name} on ".$session->starts_at->format('D d M'),
                    'client_id' => $client->id, 'due_date' => today()->addDays(2), 'created_by' => $request->user()->id,
                ]);
                Hub::alert('absence', "{$client->full_name} reached {$absences} absences", 'A parent follow-up task was created.', '/tasks', null, 'reception');
                $tasks++;
            }
        }

        return ['saved' => count($data['marks']), 'tasks_created' => $tasks];
    }

    // ---- Follow-up tasks ----------------------------------------------------

    public function tasks(Request $request)
    {
        return FollowUpTask::with('client:id,code,first_name,last_name,family_id', 'lead:id,first_name,last_name,phone', 'assignee:id,name', 'creator:id,name')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->mine, fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->orderByRaw("case status when 'pending' then 0 else 1 end")->orderBy('due_date')->latest()
            ->paginate($request->integer('per_page', 50));
    }

    public function saveTask(Request $request, ?FollowUpTask $task = null)
    {
        $data = $request->validate([
            'type' => ($task ? 'sometimes|' : '').'required|in:absence,meeting_request,conversion,waiting_list,general',
            'title' => ($task ? 'sometimes|' : '').'required|string', 'description' => 'nullable|string',
            'client_id' => 'nullable|exists:clients,id', 'lead_id' => 'nullable|exists:leads,id',
            'assigned_to' => 'nullable|exists:users,id', 'due_date' => 'nullable|date', 'status' => 'in:pending,canceled,done',
        ]);
        if ($task) {
            $task->update($data);
            if (isset($data['status']) && $task->client_id) {
                Hub::journey($task->client_id, 'task', "Task \"{$task->title}\" marked {$data['status']}", [], $task->client?->family_id);
            }
        } else {
            $task = FollowUpTask::create($data + ['created_by' => $request->user()->id]);
            if ($task->assigned_to) {
                Hub::alert('task', "New task: {$task->title}", $task->description, '/tasks', $task->assigned_to);
            }
        }

        return $task->load('assignee:id,name', 'client:id,first_name,last_name');
    }

    // ---- HR geofencing ------------------------------------------------------

    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public function clock(Request $request)
    {
        $data = $request->validate(['latitude' => 'required|numeric', 'longitude' => 'required|numeric', 'location_id' => 'nullable|exists:locations,id', 'user_id' => 'nullable|exists:users,id']);
        $userId = $request->user()->hasRole('manager') && ! empty($data['user_id']) ? $data['user_id'] : $request->user()->id;
        $location = Location::find($data['location_id'] ?? $request->user()->location_id) ?? Location::first();
        $distance = $location?->latitude !== null ? self::distanceMeters($data['latitude'], $data['longitude'], $location->latitude, $location->longitude) : null;
        $outside = $distance !== null && $distance > $location->geofence_radius_m;

        $open = StaffAttendance::where('user_id', $userId)->whereNull('check_out_at')->whereDate('check_in_at', today())->latest('check_in_at')->first();

        if ($open) {
            $open->update(['check_out_at' => now(), 'check_out_lat' => $data['latitude'], 'check_out_lng' => $data['longitude'], 'check_out_distance_m' => $distance]
                + ($outside && $open->status === 'ok' ? ['status' => 'attention_required'] : []));
            $record = $open;
            $action = 'check-out';
        } else {
            $record = StaffAttendance::create([
                'user_id' => $userId, 'location_id' => $location?->id, 'check_in_at' => now(),
                'check_in_lat' => $data['latitude'], 'check_in_lng' => $data['longitude'], 'check_in_distance_m' => $distance,
                'status' => $outside ? 'attention_required' : 'ok',
            ]);
            $action = 'check-in';
        }
        if ($outside) {
            Hub::alert('geofence', "Out-of-bounds {$action}: {$record->user->name}", "{$distance} m from {$location->name} (allowed {$location->geofence_radius_m} m)", '/hr/attendance', null, 'manager');
        }

        return ['action' => $action, 'outside' => $outside, 'distance_m' => $distance, 'record' => $record->load('user:id,name', 'location:id,name')];
    }

    public function staffAttendance(Request $request)
    {
        return StaffAttendance::with('user:id,name,role', 'location:id,name', 'reviewer:id,name')->inBranch()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->user_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->month, fn ($q, $m) => $q->where('check_in_at', 'like', "$m%"))
            ->when(! $request->user()->hasRole('manager', 'accountant'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('check_in_at')->paginate($request->integer('per_page', 50));
    }

    public function review(Request $request, StaffAttendance $record)
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected']);
        $record->update(['status' => $data['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return $record->load('user:id,name', 'reviewer:id,name');
    }
}
