<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Organization;
use App\Services\Subscription;
use App\Services\Tenant;
use App\Models\User;
use App\Services\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required']);
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active staff account.']);
        }

        return ['token' => $user->createToken('dashboard')->plainTextToken, 'user' => $this->profile($user)];
    }

    public function me(Request $request)
    {
        return $this->profile($request->user());
    }

    /** Staff profile plus the branches they may work in. */
    private function profile(User $user): User
    {
        if ($user->role === 'platform') {
            return $user->setAttribute('platform', true)->setAttribute('branches', [])->setAttribute('can_switch_branch', false);
        }
        $org = Organization::with('plan')->find($user->organization_id);
        Tenant::set($org);
        $user->setAttribute('organization', $org ? [
            'id' => $org->id, 'name' => $org->name, 'slug' => $org->slug, 'brand_color' => $org->brand_color, 'currency' => $org->currency,
            'status' => Subscription::status($org), 'trial_ends_at' => $org->trial_ends_at, 'current_period_end' => $org->current_period_end, 'cancel_at' => $org->cancel_at,
            'plan' => $org->plan?->only('id', 'code', 'name', 'features', 'max_branches', 'max_staff', 'max_clients'),
        ] : null);
        $switch = Branch::canSwitch($user);

        return $user->load('location:id,code,name')->setAttribute('can_switch_branch', $switch)->setAttribute('branches',
            Location::where('active', true)->when(! $switch, fn ($q) => $q->whereKey($user->location_id))->orderBy('code')->get(['id', 'code', 'name']));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
