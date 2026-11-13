<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PayrollPayment;
use App\Models\Registration;
use App\Models\Trial;
use Illuminate\Support\Carbon;

class Reports
{
    /** Revenue vs expenses (incl. staff payments) bucketed by day, week or month. */
    public static function financial(string $period, Carbon $from, Carbon $to): array
    {
        $key = fn (Carbon $d) => match ($period) {
            'weekly' => $d->copy()->startOfWeek()->toDateString(),
            'monthly' => $d->format('Y-m'),
            default => $d->toDateString(),
        };

        $buckets = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $buckets[$key($d)] ??= ['period' => $key($d), 'revenue' => 0.0, 'expenses' => 0.0, 'payroll' => 0.0];
        }

        foreach (Payment::inBranch()->whereBetween('created_at', [$from, $to->copy()->endOfDay()])->get(['amount', 'created_at']) as $p) {
            $buckets[$key($p->created_at)]['revenue'] += $p->amount;
        }
        foreach (Expense::inBranch()->whereIn('status', ['approved', 'paid'])->whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to)->get(['amount', 'expense_date']) as $e) {
            $buckets[$key($e->expense_date)]['expenses'] += $e->amount;
        }
        foreach (PayrollPayment::inBranch()->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)->get(['amount', 'paid_on']) as $pp) {
            $buckets[$key($pp->paid_on)]['payroll'] += $pp->amount;
        }

        $series = array_values(array_map(fn ($b) => $b + [
            'net' => round($b['revenue'] - $b['expenses'] - $b['payroll'], 2),
        ], $buckets));

        $totals = [
            'revenue' => round(array_sum(array_column($series, 'revenue')), 2),
            'expenses' => round(array_sum(array_column($series, 'expenses')), 2),
            'payroll' => round(array_sum(array_column($series, 'payroll')), 2),
        ];
        $totals['net'] = round($totals['revenue'] - $totals['expenses'] - $totals['payroll'], 2);

        return compact('series', 'totals');
    }

    /**
     * Churn / retention for a month, based on registrations that ended in that month:
     * retained = the client has a newer registration, churned = they did not renew.
     */
    public static function retention(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $ending = Registration::inBranch()->whereDate('end_date', '>=', $start)->whereDate('end_date', '<=', $end)
            ->where('status', '!=', 'canceled')->get();
        $clients = $ending->pluck('client_id')->unique();
        $base = $clients->count();

        $retained = $clients->filter(function ($clientId) use ($ending) {
            $lastEnd = $ending->where('client_id', $clientId)->max('start_date');

            return Registration::where('client_id', $clientId)->whereDate('start_date', '>', $lastEnd)
                ->where('status', '!=', 'canceled')->exists();
        })->count();

        $canceled = Registration::inBranch()->where('status', 'canceled')->whereBetween('updated_at', [$start, $end])->distinct('client_id')->count('client_id');
        $churned = ($base - $retained) + $canceled;
        $denominator = $base + $canceled;

        return [
            'month' => $start->format('Y-m'),
            'base' => $denominator,
            'retained' => $retained,
            'churned' => $churned,
            'retention_rate' => $denominator ? round($retained / $denominator * 100, 1) : null,
            'churn_rate' => $denominator ? round($churned / $denominator * 100, 1) : null,
        ];
    }

    public static function funnel(Carbon $from, Carbon $to): array
    {
        $range = [$from, $to->copy()->endOfDay()];

        return [
            'leads' => Lead::inBranch(null, true)->whereBetween('created_at', $range)->count(),
            'trials' => Trial::inBranch()->whereBetween('scheduled_at', $range)->count(),
            'approved' => Trial::inBranch()->whereBetween('scheduled_at', $range)->where('status', 'approved')->count(),
            'converted' => Lead::inBranch(null, true)->whereBetween('created_at', $range)->where('status', 'converted')->count(),
            'by_source' => Lead::inBranch(null, true)->whereBetween('created_at', $range)->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source'),
        ];
    }
}
