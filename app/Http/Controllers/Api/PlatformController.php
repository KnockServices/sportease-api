<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** SaaS owner console: academies, plans, subscription billing, MRR. */
class PlatformController extends Controller
{
    private function monthly(Organization $o): float
    {
        if (! $o->plan || Subscription::status($o) !== 'active' && Subscription::status($o) !== 'past_due') {
            return 0;
        }

        return (float) ($o->billing_cycle === 'yearly' ? round($o->plan->price_yearly / 12, 2) : $o->plan->price_monthly);
    }

    public function overview()
    {
        $orgs = Organization::with('plan')->get();
        $statuses = $orgs->groupBy(fn ($o) => Subscription::status($o))->map->count();
        $months = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i));

        return [
            'mrr' => round($orgs->sum(fn ($o) => $this->monthly($o)), 2),
            'academies' => $orgs->count(),
            'by_status' => $statuses,
            'by_plan' => $orgs->groupBy(fn ($o) => $o->plan?->name ?? 'No plan')->map->count(),
            'trials_ending' => $orgs->filter(fn ($o) => Subscription::status($o) === 'trialing' && $o->trial_ends_at?->lte(now()->addDays(7)))->values()->map->only('id', 'name', 'slug', 'trial_ends_at'),
            'open_invoices' => ['count' => SubscriptionInvoice::where('status', 'pending')->count(), 'amount' => round((float) SubscriptionInvoice::where('status', 'pending')->sum('amount'), 2),
                'overdue' => SubscriptionInvoice::where('status', 'pending')->whereDate('due_date', '<', today())->count()],
            'collected' => $months->map(fn ($m) => ['month' => $m->format('Y-m'), 'amount' => round((float) SubscriptionInvoice::where('status', 'paid')->whereBetween('paid_at', [$m, $m->copy()->endOfMonth()])->sum('amount'), 2)]),
            'signups' => $months->map(fn ($m) => ['month' => $m->format('Y-m'), 'count' => $orgs->filter(fn ($o) => $o->created_at->between($m, $m->copy()->endOfMonth()))->count()]),
        ];
    }

    public function organizations(Request $request)
    {
        return Organization::with('plan')->latest()->get()
            ->map(fn ($o) => $o->setAttribute('effective_status', Subscription::status($o))->setAttribute('usage', Subscription::usage($o))->setAttribute('mrr', $this->monthly($o))
                ->setAttribute('last_activity', User::withoutGlobalScope('tenant')->where('organization_id', $o->id)
                    ->join('personal_access_tokens', fn ($j) => $j->on('personal_access_tokens.tokenable_id', '=', 'users.id')->where('tokenable_type', User::class))
                    ->max('personal_access_tokens.last_used_at')))
            ->when($request->status, fn ($c, $s) => $c->where('effective_status', $s))->values();
    }

    public function organization(Organization $organization)
    {
        $id = $organization->id;
        $scoped = fn ($m) => $m::withoutGlobalScope('tenant')->where('organization_id', $id);

        return $organization->load('plan', 'subscriptionInvoices.plan:id,name')->setAttribute('effective_status', Subscription::status($organization))
            ->setAttribute('usage', Subscription::usage($organization))->setAttribute('mrr', $this->monthly($organization))
            ->setAttribute('owner', $scoped(User::class)->where('role', 'admin')->oldest('id')->first(['id', 'name', 'email', 'phone']))
            ->setAttribute('activity', [
                'clients_30d' => $scoped(Client::class)->where('created_at', '>=', now()->subDays(30))->count(),
                'bookings_30d' => $scoped(Reservation::class)->where('created_at', '>=', now()->subDays(30))->count(),
                'invoiced_30d' => round((float) $scoped(Invoice::class)->where('created_at', '>=', now()->subDays(30))->sum('total'), 2),
            ]);
    }

    public function updateOrganization(Request $request, Organization $organization)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120', 'plan_id' => 'sometimes|nullable|exists:plans,id', 'billing_cycle' => 'sometimes|in:monthly,yearly',
            'status' => ['sometimes', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'canceled'])],
            'trial_ends_at' => 'sometimes|nullable|date', 'current_period_end' => 'sometimes|nullable|date', 'platform_notes' => 'sometimes|nullable|string',
        ]);
        $organization->update($data);

        return $this->organization($organization->fresh());
    }

    public function issueInvoice(Request $request, Organization $organization)
    {
        $data = $request->validate(['plan_id' => 'nullable|exists:plans,id', 'billing_cycle' => 'nullable|in:monthly,yearly', 'amount' => 'nullable|numeric|min:0', 'notes' => 'nullable|string']);
        $inv = Subscription::issue($organization, isset($data['plan_id']) ? Plan::find($data['plan_id']) : null, $data['billing_cycle'] ?? null, 'change');
        $inv->update(array_filter(['amount' => $data['amount'] ?? null, 'notes' => $data['notes'] ?? null], fn ($v) => $v !== null));

        return $inv;
    }

    public function invoices(Request $request)
    {
        return SubscriptionInvoice::with('organization:id,name,slug', 'plan:id,name')
            ->when($request->status === 'overdue', fn ($q) => $q->where('status', 'pending')->whereDate('due_date', '<', today()))
            ->when($request->status && $request->status !== 'overdue', fn ($q) => $q->where('status', $request->status))
            ->latest('id')->paginate(50);
    }

    public function payInvoice(Request $request, SubscriptionInvoice $invoice)
    {
        $data = $request->validate(['method' => 'required|in:transfer,whish,omt,cash,card', 'reference' => 'nullable|string|max:120']);

        return Subscription::markPaid($invoice, $data['method'], $data['reference'] ?? null);
    }

    public function voidInvoice(SubscriptionInvoice $invoice)
    {
        abort_if($invoice->status !== 'pending', 422, 'Only pending invoices can be voided.');
        $invoice->update(['status' => 'void']);

        return $invoice;
    }

    public function plans()
    {
        return Plan::withCount('organizations')->orderBy('sort')->get();
    }

    public function savePlan(Request $request, ?Plan $plan = null)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9-]+$/', Rule::unique('plans')->ignore($plan?->id)],
            'name' => 'required|string|max:60', 'tagline' => 'nullable|string|max:160',
            'price_monthly' => 'required|numeric|min:0', 'price_yearly' => 'required|numeric|min:0',
            'max_branches' => 'nullable|integer|min:1', 'max_staff' => 'nullable|integer|min:1', 'max_clients' => 'nullable|integer|min:1',
            'features' => 'array', 'features.*' => Rule::in(array_keys(Plan::FEATURES)),
            'trial_days' => 'required|integer|min:0|max:90', 'sort' => 'integer', 'public' => 'boolean', 'active' => 'boolean',
        ]);
        $plan ? $plan->update($data) : $plan = Plan::create($data);

        return $plan;
    }
}
