<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Subscription lifecycle for an academy:
 *   trialing → (paid) active → (period ends unpaid) past_due → (after the grace period) suspended.
 * Payments are recorded by the platform team (bank transfer, Whish, OMT, card terminal …).
 */
class Subscription
{
    public const GRACE_DAYS = 7;

    public const LIMITS = ['branches' => 'max_branches', 'staff' => 'max_staff', 'clients' => 'max_clients'];

    /** Status after applying dates (trial end, period end + grace). */
    public static function status(Organization $org): string
    {
        if (in_array($org->status, ['suspended', 'canceled'])) {
            return $org->status;
        }
        if ($org->status === 'trialing') {
            return $org->trial_ends_at && $org->trial_ends_at->isPast() ? 'trial_expired' : 'trialing';
        }
        if ($org->current_period_end && $org->current_period_end->isPast()) {
            return $org->current_period_end->copy()->addDays(self::GRACE_DAYS)->isPast() ? 'suspended' : 'past_due';
        }

        return 'active';
    }

    /** Can the academy use the hub right now? (Billing pages stay open regardless.) */
    public static function canOperate(Organization $org): bool
    {
        return in_array(self::status($org), ['trialing', 'active', 'past_due']);
    }

    /** @return array<string, array{used:int, limit:?int}> */
    public static function usage(Organization $org): array
    {
        $plan = $org->plan;
        $count = fn ($model, $extra = null) => $model::withoutGlobalScope('tenant')->where('organization_id', $org->id)->when($extra, $extra)->count();

        return [
            'branches' => ['used' => $count(Location::class, fn ($q) => $q->where('active', true)), 'limit' => $plan?->max_branches],
            'staff' => ['used' => $count(User::class, fn ($q) => $q->where('active', true)), 'limit' => $plan?->max_staff],
            'clients' => ['used' => $count(Client::class), 'limit' => $plan?->max_clients],
        ];
    }

    /** Throws when adding one more $what would exceed the plan. */
    public static function assertCanAdd(string $what): void
    {
        $org = Tenant::org();
        if (! $org || ! $org->plan) {
            return;
        }
        $u = self::usage($org)[$what];
        if ($u['limit'] !== null && $u['used'] >= $u['limit']) {
            throw ValidationException::withMessages([$what => "Your {$org->plan->name} plan includes up to {$u['limit']} {$what}. Upgrade the plan under Subscription to add more."]);
        }
    }

    public static function feature(Organization $org, string $feature): bool
    {
        return (bool) $org->plan?->has($feature);
    }

    /** Self-service sign-up: organization, first branch, owner account, defaults. */
    public static function signUp(array $d): array
    {
        return DB::transaction(function () use ($d) {
            $plan = Plan::where('code', $d['plan'] ?? null)->where('active', true)->first() ?? Plan::where('active', true)->orderBy('sort')->first();
            $org = Organization::create([
                'name' => $d['academy_name'], 'slug' => $d['slug'], 'plan_id' => $plan?->id, 'billing_cycle' => $d['billing_cycle'] ?? 'monthly',
                'status' => 'trialing', 'trial_ends_at' => now()->addDays($plan?->trial_days ?? 14)->endOfDay(),
                'owner_name' => $d['name'], 'owner_email' => $d['email'], 'phone' => $d['phone'] ?? null,
                'country' => $d['country'] ?? null, 'currency' => $d['currency'] ?? 'USD', 'timezone' => $d['timezone'] ?? 'Asia/Beirut',
            ]);

            return Tenant::run($org, function () use ($org, $d) {
                $branch = Location::create(['code' => 'BR-01', 'name' => $d['branch_name'] ?? 'Main branch', 'address' => $d['city'] ?? null, 'geofence_radius_m' => 150]);
                $user = User::create([
                    'name' => $d['name'], 'email' => $d['email'], 'password' => $d['password'], 'phone' => $d['phone'] ?? null,
                    'role' => 'admin', 'location_id' => $branch->id, 'pay_type' => 'monthly', 'pay_rate' => 0,
                ]);
                $branch->update(['manager_id' => $user->id]);
                \App\Models\Family::create(['code' => 'WALK-IN', 'name' => 'Walk-in customers']);

                return ['organization' => $org, 'user' => $user];
            });
        });
    }

    /** Issue a subscription invoice for the next period (or a plan change). */
    public static function issue(Organization $org, ?Plan $plan = null, ?string $cycle = null, string $kind = 'renewal'): SubscriptionInvoice
    {
        $plan ??= $org->plan;
        $cycle ??= $org->billing_cycle;
        $open = $org->subscriptionInvoices()->where('status', 'pending')->first();
        if ($open && $kind === 'renewal') {
            return $open;
        }
        // Renewals continue from the paid-until date (no gap); first purchases and plan changes start today.
        $start = $kind === 'renewal' && $org->current_period_end ? $org->current_period_end->copy()->startOfDay() : today();
        $end = $cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth();
        if ($open) {
            $open->update(['status' => 'void', 'notes' => trim(($open->notes ?? '').' Replaced by a plan change.')]);
        }

        return SubscriptionInvoice::create([
            'organization_id' => $org->id, 'number' => Hub::sequence('subscription_invoices', 'number', 'SUB-'.now()->format('y').'-', 4),
            'plan_id' => $plan->id, 'billing_cycle' => $cycle, 'amount' => $plan->price($cycle),
            'period_start' => $start, 'period_end' => $end, 'due_date' => $start->copy()->addDays(self::GRACE_DAYS), 'kind' => $kind,
        ]);
    }

    /** Payment received: activate the academy on the invoiced plan until the period end. */
    public static function markPaid(SubscriptionInvoice $inv, string $method, ?string $reference = null): SubscriptionInvoice
    {
        if ($inv->status !== 'pending') {
            throw ValidationException::withMessages(['invoice' => "Invoice {$inv->number} is {$inv->status}."]);
        }

        return DB::transaction(function () use ($inv, $method, $reference) {
            $inv->update(['status' => 'paid', 'paid_at' => now(), 'method' => $method, 'reference' => $reference]);
            $org = $inv->organization;
            $periodEnd = Carbon::parse($inv->period_end)->endOfDay();
            $org->update([
                'status' => 'active', 'plan_id' => $inv->plan_id, 'billing_cycle' => $inv->billing_cycle,
                'current_period_end' => $org->current_period_end && $org->current_period_end->gt($periodEnd) ? $org->current_period_end : $periodEnd,
                'cancel_at' => null,
            ]);

            return $inv->fresh();
        });
    }

    /** Daily: renewal invoices 7 days ahead, and store the computed status. */
    public static function nightly(): array
    {
        $issued = 0;
        $changed = 0;
        foreach (Organization::with('plan')->get() as $org) {
            if ($org->status === 'active' && $org->current_period_end && $org->current_period_end->lte(now()->addDays(7)) && ! $org->cancel_at && $org->plan) {
                $inv = self::issue($org);
                $issued += $inv->wasRecentlyCreated ? 1 : 0;
            }
            if ($org->cancel_at && $org->cancel_at->isPast() && $org->status !== 'canceled') {
                $org->update(['status' => 'canceled']);
                $changed++;
            } elseif (in_array($s = self::status($org), ['past_due', 'suspended']) && $org->status !== $s) {
                $org->update(['status' => $s]);
                $changed++;
            }
        }

        return compact('issued', 'changed');
    }
}
