<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\Subscription;
use App\Services\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Public plans & academy sign-up, and the academy's own subscription page. */
class SaasController extends Controller
{
    private const RESERVED = ['www', 'api', 'app', 'admin', 'platform', 'login', 'signup', 'billing', 'knock'];

    public function plans()
    {
        return Plan::where('active', true)->where('public', true)->orderBy('sort')->get();
    }

    public function slugCheck(Request $request)
    {
        $slug = Str::slug((string) $request->input('slug'));

        return ['slug' => $slug, 'available' => strlen($slug) >= 3 && ! in_array($slug, self::RESERVED) && ! Organization::where('slug', $slug)->exists()];
    }

    public function signup(Request $request)
    {
        $data = $request->validate([
            'academy_name' => 'required|string|max:120',
            'slug' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::notIn(self::RESERVED), 'unique:organizations,slug'],
            'branch_name' => 'nullable|string|max:120', 'city' => 'nullable|string|max:120', 'country' => 'nullable|string|max:60',
            'name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'phone' => 'nullable|string|max:40',
            'password' => 'required|string|min:8|confirmed',
            'plan' => 'nullable|exists:plans,code', 'billing_cycle' => 'nullable|in:monthly,yearly',
        ], ['slug.unique' => 'That academy address is taken.', 'slug.regex' => 'Use lowercase letters, numbers and dashes.']);

        ['organization' => $org, 'user' => $user] = Subscription::signUp($data);

        return response()->json(['token' => $user->createToken('dashboard')->plainTextToken, 'organization' => $org], 201);
    }

    /** The academy's plan, status, usage and subscription invoices. */
    public function subscription()
    {
        $org = Tenant::org()->load('plan');

        return [
            'organization' => $org->makeHidden('platform_notes'),
            'status' => Subscription::status($org),
            'usage' => Subscription::usage($org),
            'invoices' => $org->subscriptionInvoices()->with('plan:id,name')->get(),
            'plans' => $this->plans(),
            'grace_days' => Subscription::GRACE_DAYS,
            'payment_instructions' => config('saas.payment_instructions'),
        ];
    }

    public function updateAccount(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'phone' => 'nullable|string|max:40', 'country' => 'nullable|string|max:60',
            'currency' => 'nullable|string|size:3', 'timezone' => ['nullable', Rule::in(timezone_identifiers_list())], 'brand_color' => 'nullable|string|max:9',
        ]);
        Tenant::org()->update(array_filter($data, fn ($v) => $v !== null) + ['name' => $data['name']]);

        return Tenant::org()->fresh();
    }

    /** Choose / change plan: issues an invoice; the plan switches once it is paid. Downgrades are checked against usage. */
    public function changePlan(Request $request)
    {
        $data = $request->validate(['plan_id' => 'required|exists:plans,id', 'billing_cycle' => 'required|in:monthly,yearly']);
        $org = Tenant::org();
        $plan = Plan::where('active', true)->findOrFail($data['plan_id']);
        foreach (Subscription::usage($org) as $what => $u) {
            $limit = $plan->{Subscription::LIMITS[$what]};
            if ($limit !== null && $u['used'] > $limit) {
                throw ValidationException::withMessages(['plan_id' => "{$plan->name} allows {$limit} {$what}; you have {$u['used']}. Deactivate some first."]);
            }
        }
        if ($plan->price($data['billing_cycle']) <= 0) {
            $org->update(['plan_id' => $plan->id, 'billing_cycle' => $data['billing_cycle'], 'status' => 'active', 'current_period_end' => null]);

            return ['invoice' => null, 'organization' => $org->fresh('plan')];
        }

        return ['invoice' => Subscription::issue($org, $plan, $data['billing_cycle'], $org->status === 'trialing' ? 'first' : 'change'), 'organization' => $org];
    }

    public function cancel()
    {
        $org = Tenant::org();
        $org->update(['cancel_at' => $org->current_period_end ?? $org->trial_ends_at ?? now()]);
        $org->subscriptionInvoices()->where('status', 'pending')->where('kind', 'renewal')->update(['status' => 'void', 'notes' => 'Canceled by the academy.']);

        return $org;
    }

    public function resume()
    {
        Tenant::org()->update(['cancel_at' => null]);

        return Tenant::org();
    }
}
