<?php

namespace App\Services;

use App\Models\Payroll;
use App\Models\StaffAttendance;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Monthly payroll from daily / hourly / flat monthly rates. Unpaid balances roll into
 * the following month's "carried over" amount.
 */
class PayrollService
{
    public static function generate(string $month): int
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $prevMonth = $start->copy()->subMonth()->format('Y-m');
        $count = 0;

        foreach (User::where('active', true)->where('pay_rate', '>', 0)->get() as $user) {
            $records = StaffAttendance::where('user_id', $user->id)
                ->whereBetween('check_in_at', [$start, $end])
                ->whereIn('status', ['ok', 'approved']) // rejected = absent, attention_required = not yet counted
                ->get();

            $days = $records->groupBy(fn ($r) => $r->check_in_at->toDateString())->count();
            $hours = round($records->sum(fn ($r) => $r->check_out_at ? $r->check_in_at->diffInMinutes($r->check_out_at) / 60 : 0), 2);

            $earned = match ($user->pay_type) {
                'daily' => $days * $user->pay_rate,
                'hourly' => $hours * $user->pay_rate,
                default => $user->pay_rate,
            };

            $previous = Payroll::where('user_id', $user->id)->where('month', $prevMonth)->first();

            Payroll::updateOrCreate(
                ['user_id' => $user->id, 'month' => $month],
                [
                    'pay_type' => $user->pay_type,
                    'pay_rate' => $user->pay_rate,
                    'days_worked' => $days,
                    'hours_worked' => $hours,
                    'earned' => round($earned, 2),
                    'carried_over' => $previous ? max(0, $previous->balance) : 0,
                ],
            );
            $count++;
        }

        return $count;
    }
}
