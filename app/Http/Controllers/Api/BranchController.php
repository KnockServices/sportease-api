<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Registration;
use App\Models\Reservation;
use App\Models\Team;
use App\Models\TeamSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Branches: details, manager, opening hours, geofence and a side-by-side performance overview. */
class BranchController extends Controller
{
    public function index()
    {
        $monthStart = now()->startOfMonth();

        return Location::with('manager:id,name,phone,email')->orderBy('code')->orderBy('name')->get()->map(function (Location $b) use ($monthStart) {
            $id = $b->id;

            return $b->setAttribute('stats', [
                'courts' => Court::inBranch($id)->whereNull('parent_id')->count(),
                'sub_courts' => Court::inBranch($id)->whereNotNull('parent_id')->count(),
                'teams' => Team::inBranch($id)->where('active', true)->count(),
                'staff' => User::inBranch($id)->where('active', true)->count(),
                'active_clients' => Registration::inBranch($id)->where('status', 'active')->distinct('client_id')->count('client_id'),
                'open_leads' => Lead::inBranch($id)->whereIn('status', ['new', 'trial_scheduled', 'trial_approved'])->count(),
                'revenue_month' => round((float) Payment::inBranch($id)->where('created_at', '>=', $monthStart)->sum('amount'), 2),
                'expenses_month' => round((float) Expense::inBranch($id)->whereIn('status', ['approved', 'paid'])->whereDate('expense_date', '>=', $monthStart)->sum('amount'), 2),
                'outstanding' => round((float) Invoice::inBranch($id)->whereIn('status', ['unpaid', 'partial'])->sum(DB::raw('total - paid_amount')), 2),
                'bookings_today' => Reservation::inBranch($id)->where('status', 'confirmed')->whereDate('starts_at', today())->count(),
                'sessions_today' => TeamSession::inBranch($id)->where('status', 'scheduled')->whereDate('starts_at', today())->count(),
            ]);
        });
    }

    public function save(Request $request, ?Location $location = null)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'code' => ['nullable', 'string', 'max:10', Rule::unique('locations')->ignore($location?->id)],
            'address' => 'nullable|string', 'phone' => 'nullable|string|max:40', 'email' => 'nullable|email',
            'manager_id' => 'nullable|exists:users,id',
            'opens_at' => 'nullable|date_format:H:i', 'closes_at' => 'nullable|date_format:H:i|after:opens_at',
            'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180',
            'geofence_radius_m' => 'nullable|integer|min:10|max:5000', 'active' => 'boolean', 'notes' => 'nullable|string',
        ]);
        $data['geofence_radius_m'] ??= 150;
        if (! $location && empty($data['code'])) {
            $data['code'] = 'BR-'.str_pad((string) (Location::count() + 1), 2, '0', STR_PAD_LEFT);
        }
        if ($location && ($data['active'] ?? true) === false && Location::where('active', true)->whereKeyNot($location->id)->doesntExist()) {
            abort(422, 'At least one branch must stay active.');
        }
        $location ? $location->update($data) : $location = Location::create($data);
        // The branch manager works at this branch.
        if ($location->manager_id) {
            User::whereKey($location->manager_id)->whereNull('location_id')->update(['location_id' => $location->id]);
        }

        return $location->load('manager:id,name');
    }
}
