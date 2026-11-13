<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\Location;
use App\Models\Package;
use App\Models\Team;
use App\Models\User;
use App\Services\Hub;
use App\Services\Scheduling;
use App\Services\WaitingList;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Setup data: locations, courts & sub-courts, teams & schedules, packages, staff, settings. */
class CatalogController extends Controller
{
    public function lookups()
    {
        return [
            'locations' => Location::where('active', true)->orderBy('name')->get(),
            'courts' => Court::where('active', true)->inBranch()->with('parent:id,code,name', 'rates:id,court_id,duration_min,price')->orderBy('location_id')->orderByRaw('coalesce(parent_id, id)')->orderBy('id')->get(),
            'teams' => Team::where('active', true)->inBranch()->orderBy('name')->get(['id', 'name', 'coach_id', 'location_id', 'capacity', 'color', 'age_group']),
            'packages' => Package::where('active', true)->orderBy('name')->get(),
            'staff' => User::where('active', true)->inBranch(null, true)->orderBy('name')->get(['id', 'name', 'role', 'location_id']),
        ];
    }

    public function locations()
    {
        return Location::withCount('courts')->orderBy('name')->get();
    }

    public function saveLocation(Request $request, ?Location $location = null)
    {
        $data = $request->validate([
            'name' => 'required|string', 'address' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'geofence_radius_m' => 'integer|min:10|max:5000', 'active' => 'boolean',
        ]);
        $location ? $location->update($data) : $location = Location::create($data);

        return $location;
    }

    public function courts()
    {
        return Court::with('location:id,name', 'parent:id,code,name', 'rates')->withCount('children')->inBranch()
            ->orderBy('location_id')->orderByRaw('coalesce(parent_id, id)')->orderBy('id')->get();
    }

    public function saveCourt(Request $request, ?Court $court = null)
    {
        $data = $request->validate([
            'location_id' => 'required|exists:locations,id',
            'code' => ['nullable', 'string', 'max:20', Rule::unique('courts')->ignore($court?->id)],
            // A sub-court must reference a full (top-level) court by its ID.
            'parent_id' => ['nullable', Rule::exists('courts', 'id')->whereNull('parent_id'), Rule::notIn([$court?->id])],
            'name' => 'required|string', 'kind' => 'required|in:full,half,quarter',
            'hourly_rate' => 'numeric|min:0', 'active' => 'boolean',
        ], ['parent_id.exists' => 'Choose a full court (not a sub-court) as the parent.']);

        if (! empty($data['parent_id'])) {
            $parent = Court::find($data['parent_id']);
            if ($parent->location_id != $data['location_id']) {
                throw ValidationException::withMessages(['parent_id' => "{$parent->label} is at another location."]);
            }
            if ($data['kind'] === 'full') {
                throw ValidationException::withMessages(['kind' => 'A sub-court must be a half or quarter court.']);
            }
        } elseif ($court && $court->children()->exists() && ($data['kind'] ?? 'full') !== 'full') {
            throw ValidationException::withMessages(['kind' => 'This court has sub-courts, so it must stay a full court.']);
        }
        if (empty($data['code'])) {
            unset($data['code']);
        }
        $court ? $court->update($data) : $court = Court::create($data);

        return $court->load('parent:id,code,name', 'location:id,name', 'rates');
    }

    /** Replace a court's duration price tiers. Body: { rates: [{duration_min, price}, …] } */
    public function saveRates(Request $request, Court $court)
    {
        $data = $request->validate([
            'rates' => 'present|array', 'rates.*.duration_min' => 'required|integer|min:15|max:1440|distinct', 'rates.*.price' => 'required|numeric|min:0',
            'hourly_rate' => 'nullable|numeric|min:0',
        ]);
        if (isset($data['hourly_rate'])) {
            $court->update(['hourly_rate' => $data['hourly_rate']]);
        }
        $court->rates()->delete();
        foreach ($data['rates'] as $r) {
            $court->rates()->create($r);
        }
        Hub::audit('court.pricing', $court, null, null, "Pricing updated for {$court->label}", ['rates' => $data['rates']]);

        return $court->load('rates');
    }

    /** Split a full court into halves or quarters; each sub-court carries the full court's ID (C-01-A …). */
    public function splitCourt(Request $request, Court $court)
    {
        if ($court->parent_id) {
            throw ValidationException::withMessages(['court' => 'Only a full court can be split.']);
        }
        if ($court->children()->exists()) {
            throw ValidationException::withMessages(['court' => "{$court->label} is already split."]);
        }
        $data = $request->validate(['into' => 'required|in:2,4', 'hourly_rate' => 'nullable|numeric|min:0']);
        $kind = $data['into'] == 2 ? 'half' : 'quarter';
        $labels = $data['into'] == 2 ? ['A', 'B'] : ['Q1', 'Q2', 'Q3', 'Q4'];
        $rate = $data['hourly_rate'] ?? round($court->hourly_rate / $data['into'], 2);
        // Sub-court tiers default to the full court's tiers scaled the same way as the hourly rate.
        $factor = $court->hourly_rate > 0 ? $rate / $court->hourly_rate : 1 / $data['into'];

        return collect($labels)->map(function ($l) use ($court, $kind, $rate, $factor) {
            $sub = Court::create([
                'location_id' => $court->location_id, 'parent_id' => $court->id, 'code' => "{$court->code}-{$l}", 'name' => "{$court->name} – {$l}",
                'kind' => $kind, 'hourly_rate' => $rate,
            ]);
            foreach ($court->rates as $r) {
                $sub->rates()->create(['duration_min' => $r->duration_min, 'price' => round($r->price * $factor, 2)]);
            }

            return $sub->load('rates');
        });
    }

    public function teams()
    {
        return Team::with('coach:id,name', 'location:id,name', 'schedules.court:id,code,name', 'packages')->inBranch()
            ->withCount(['registrations as enrolled_count' => fn ($q) => $q->where('status', 'active'), 'waitingList as waiting_count' => fn ($q) => $q->where('status', 'waiting')])
            ->orderBy('name')->get();
    }

    public function saveTeam(Request $request, ?Team $team = null)
    {
        $data = $request->validate([
            'name' => 'required|string', 'location_id' => 'required|exists:locations,id', 'coach_id' => 'nullable|exists:users,id',
            'age_group' => 'nullable|string', 'capacity' => 'required|integer|min:1', 'color' => 'nullable|string', 'active' => 'boolean',
            'schedules' => 'array', 'schedules.*.weekday' => 'required|integer|between:0,6',
            'schedules.*.start_time' => 'required|date_format:H:i', 'schedules.*.end_time' => 'required|date_format:H:i|after:schedules.*.start_time',
            'schedules.*.court_id' => 'nullable|exists:courts,id',
        ]);
        $schedules = $data['schedules'] ?? null;
        unset($data['schedules']);
        $capacityRaised = $team && $data['capacity'] > $team->capacity;

        $team ? $team->update($data) : $team = Team::create($data);

        if ($schedules !== null) {
            $team->schedules()->delete();
            foreach ($schedules as $s) {
                $team->schedules()->create($s);
            }
            // Drop future untouched generated sessions, then regenerate from the new pattern.
            $team->sessions()->where('starts_at', '>', now())->where('status', 'scheduled')->whereDoesntHave('attendances')->delete();
            Scheduling::generateSessions(28, $team->id);
        }
        if ($capacityRaised) {
            WaitingList::process($team);
        }

        return $team->load('schedules', 'coach:id,name');
    }

    public function packages()
    {
        return Package::with('team:id,name')->orderBy('name')->get();
    }

    public function savePackage(Request $request, ?Package $package = null)
    {
        $data = $request->validate([
            'name' => 'required|string', 'type' => 'required|in:team,sessions', 'team_id' => 'nullable|exists:teams,id', 'price' => 'required|numeric|min:0',
            'duration_months' => 'required|integer|min:1|max:24', 'sessions_per_week' => 'nullable|integer|min:1|max:7',
            'sessions_count' => 'required_if:type,sessions|nullable|integer|min:1|max:500', 'session_minutes' => 'nullable|integer|min:15|max:480',
            'description' => 'nullable|string', 'active' => 'boolean',
        ], ['sessions_count.required_if' => 'Enter how many sessions the package includes.']);
        if ($data['type'] === 'sessions') {
            $data['team_id'] = null; // session packages are not tied to a team roster
        } else {
            $data['sessions_count'] = null;
        }
        $data['sessions_per_week'] ??= 1;
        $package ? $package->update($data) : $package = Package::create($data);

        return $package;
    }

    public function staff()
    {
        return User::with('location:id,name')->inBranch(null, true)->orderBy('name')->get();
    }

    public function saveStaff(Request $request, ?User $user = null)
    {
        $data = $request->validate([
            'name' => 'required|string', 'email' => ['required', 'email', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'role' => 'required|in:'.implode(',', User::ROLES), 'phone' => 'nullable|string',
            'location_id' => 'nullable|exists:locations,id', 'pay_type' => 'required|in:daily,hourly,monthly',
            'pay_rate' => 'numeric|min:0', 'active' => 'boolean',
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user ? $user->update($data) : $user = User::create($data);

        return $user->load('location:id,name');
    }

    public const SETTINGS = [
        'shop.show_out_of_stock' => false,
        'shop.hidden_product_ids' => [],
        'payments.gateway_mode' => 'low', // full | low
        'payments.gateway_name' => 'Areeba',
        'payments.methods' => ['cash', 'card', 'wallet', 'transfer', 'whish', 'omt'],
        'waitlist.response_hours' => 48,
        'whatsapp.enabled' => true,
        'billing.due_days' => 7,
        'attendance.absence_threshold' => 2,
    ];

    public function settings()
    {
        return collect(self::SETTINGS)->map(fn ($default, $key) => Hub::setting($key, $default));
    }

    public function saveSettings(Request $request)
    {
        // Body: { "settings": { "shop.show_out_of_stock": true, ... } }
        foreach ((array) $request->input('settings', []) as $key => $value) {
            if (array_key_exists($key, self::SETTINGS)) {
                Hub::setSetting($key, $value);
            }
        }

        return $this->settings();
    }
}
