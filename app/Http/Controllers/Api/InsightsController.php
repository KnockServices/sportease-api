<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ClientEvent;
use App\Models\CommunicationLog;
use App\Models\Family;
use App\Models\FollowUpTask;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PayrollPayment;
use App\Models\Registration;
use App\Models\StaffAlert;
use App\Models\StaffAttendance;
use App\Models\TeamSession;
use App\Models\Trial;
use App\Models\WaitingListEntry;
use App\Services\Billing;
use App\Services\Hub;
use App\Services\PayrollService;
use App\Services\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reception dashboard, alerts, logs, payroll and analytics. */
class InsightsController extends Controller
{
    public function reception()
    {
        $restricted = Invoice::whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today())->distinct()->pluck('family_id')
            ->filter(fn ($id) => Billing::status($id)['restricted'])->values();

        return [
            'todays_trials' => Trial::with('lead', 'coach:id,name', 'court:id,code,name')->inBranch()->whereDate('scheduled_at', today())->orderBy('scheduled_at')->get(),
            'pending_registration' => Lead::inBranch(null, true)->where('status', 'trial_approved')->with(['trials' => fn ($q) => $q->where('status', 'approved')->latest('scheduled_at')])->latest('updated_at')->get(),
            'first_day' => Registration::with('client:id,code,first_name,last_name', 'team:id,name', 'package:id,name')->inBranch()->whereDate('start_date', today())->where('status', 'active')->get(),
            'todays_sessions' => TeamSession::with('team:id,name,color', 'court:id,code,name', 'coach:id,name', 'substituteCoach:id,name')->inBranch()->whereDate('starts_at', today())->where('status', 'scheduled')->orderBy('starts_at')->get(),
            'stats' => [
                'active_clients' => Registration::inBranch()->where('status', 'active')->distinct('client_id')->count('client_id'),
                'new_leads_week' => Lead::inBranch(null, true)->where('created_at', '>=', now()->subDays(7))->count(),
                'revenue_today' => round((float) Payment::inBranch()->whereDate('created_at', today())->sum('amount'), 2),
                'overdue_total' => round((float) Invoice::inBranch()->whereIn('status', ['unpaid', 'partial'])->whereDate('due_date', '<', today())->sum(DB::raw('total - paid_amount')), 2),
                'restricted_families' => $restricted->count(),
                'pending_tasks' => FollowUpTask::where('status', 'pending')->count(),
                'waiting_list' => WaitingListEntry::inBranch()->whereIn('status', ['waiting', 'offered'])->count(),
                'attention_required' => StaffAttendance::inBranch()->where('status', 'attention_required')->count(),
            ],
            'restricted' => Family::whereIn('id', $restricted)->get()->map(fn ($f) => $f->setAttribute('billing', Billing::status($f))),
        ];
    }

    public function alerts(Request $request)
    {
        $user = $request->user();

        // Admins see every role alert; managers also see reception alerts.
        $roles = match ($user->role) {
            'admin' => null,
            'manager' => ['manager', 'reception'],
            default => [$user->role],
        };

        return StaffAlert::where(fn ($q) => $q->where('user_id', $user->id)->orWhere(fn ($q) => $q->whereNull('user_id')
            ->when($roles, fn ($q) => $q->where(fn ($q) => $q->whereNull('role')->orWhereIn('role', $roles)))))
            ->latest()->limit(50)->get();
    }

    public function readAlerts(Request $request)
    {
        StaffAlert::whereIn('id', (array) $request->input('ids', []))->update(['read_at' => now()]);

        return response()->noContent();
    }

    // ---- Logs ---------------------------------------------------------------

    public function auditLogs(Request $request)
    {
        return AuditLog::with('user:id,name', 'family:id,code,name')
            ->when($request->action, fn ($q, $a) => $q->where('action', 'like', "$a%"))
            ->when($request->family_id, fn ($q, $f) => $q->where('family_id', $f))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($request->integer('per_page', 50));
    }

    public function communicationLogs(Request $request)
    {
        return CommunicationLog::with('client:id,code,first_name,last_name', 'lead:id,first_name,last_name')
            ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('recipient', 'like', "%$s%")->orWhere('body', 'like', "%$s%")))
            ->latest()->paginate($request->integer('per_page', 50));
    }

    public function journeys(Request $request)
    {
        return ClientEvent::with('client:id,code,first_name,last_name', 'actor:id,name')
            ->when($request->client_id, fn ($q, $c) => $q->where('client_id', $c))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate($request->integer('per_page', 50));
    }

    // ---- Payroll ------------------------------------------------------------

    public function payroll(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));

        return Payroll::with('user:id,name,role,location_id', 'payments')->where('month', $month)->inBranch()->get()->sortBy('user.name')->values();
    }

    public function generatePayroll(Request $request)
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);

        return ['generated' => PayrollService::generate($data['month'])];
    }

    public function payPayroll(Request $request, Payroll $payroll)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'method' => 'in:cash,transfer,cheque', 'paid_on' => 'nullable|date']);
        if ($data['amount'] > $payroll->balance + 0.001) {
            throw ValidationException::withMessages(['amount' => "Amount exceeds the outstanding balance (\${$payroll->balance})."]);
        }
        $payment = PayrollPayment::create($data + ['payroll_id' => $payroll->id, 'paid_on' => $data['paid_on'] ?? today(), 'created_by' => $request->user()->id]);
        $payroll->increment('paid', $data['amount']);
        Hub::audit('payroll.paid', $payment, null, $payment->amount, "Payroll {$payroll->month} payment to {$payroll->user->name}");

        // Roll the new balance forward to an already-generated next month.
        $next = Payroll::where('user_id', $payroll->user_id)->where('month', Carbon::createFromFormat('Y-m-d', $payroll->month.'-01')->addMonth()->format('Y-m'))->first();
        $next?->update(['carried_over' => max(0, $payroll->fresh()->balance)]);

        return $payroll->fresh()->load('user:id,name,role', 'payments');
    }

    // ---- Analytics ----------------------------------------------------------

    public function financial(Request $request)
    {
        $period = $request->input('period', 'daily');
        $to = Carbon::parse($request->input('to', today()));
        $from = Carbon::parse($request->input('from', match ($period) {
            'monthly' => $to->copy()->subMonths(11)->startOfMonth(),
            'weekly' => $to->copy()->subWeeks(11)->startOfWeek(),
            default => $to->copy()->subDays(29),
        }));

        return Reports::financial($period, $from->startOfDay(), $to);
    }

    public function retention(Request $request)
    {
        $months = collect(range(5, 0))->map(fn ($i) => Reports::retention(now()->startOfMonth()->subMonths($i + 1)));

        return ['previous_month' => $months->last(), 'trend' => $months->values()];
    }

    public function funnel(Request $request)
    {
        return Reports::funnel(Carbon::parse($request->input('from', today()->subDays(30))), Carbon::parse($request->input('to', today())));
    }
}
