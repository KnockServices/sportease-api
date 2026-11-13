<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController as Branches;
use App\Http\Controllers\Api\CatalogController as Catalog;
use App\Http\Controllers\Api\EnrolmentController as Enrolment;
use App\Http\Controllers\Api\FamilyController as Families;
use App\Http\Controllers\Api\FinanceController as Finance;
use App\Http\Controllers\Api\InsightsController as Insights;
use App\Http\Controllers\Api\LeadController as Leads;
use App\Http\Controllers\Api\OperationsController as Ops;
use App\Http\Controllers\Api\PlatformController as Platform;
use App\Http\Controllers\Api\SaasController as Saas;
use App\Http\Controllers\Api\ScheduleController as Schedule;
use App\Http\Controllers\Api\TournamentController as Tournaments;
use Illuminate\Support\Facades\Route;

// ---- Public endpoints (website / social forms, SMS verification link) ----------
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:30,1,login');
Route::post('public/{academy}/leads', [Leads::class, 'capture'])->middleware(['throttle:20,1,leads', 'tenant.public']);
Route::post('public/verify', [Families::class, 'verify'])->middleware('throttle:10,1,verify');

// ---- SaaS: plans & self-service academy sign-up --------------------------------
Route::get('public/plans', [Saas::class, 'plans']);
Route::get('public/slug-check', [Saas::class, 'slugCheck'])->middleware('throttle:30,1,slug');
Route::post('public/signup', [Saas::class, 'signup'])->middleware('throttle:5,1,signup');

// ---- Signed-in profile (academy staff and platform owners) ----------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
});

// ---- Platform console (SaaS owner) ------------------------------------------------
Route::middleware(['auth:sanctum', 'platform'])->prefix('platform')->group(function () {
    Route::get('overview', [Platform::class, 'overview']);
    Route::get('organizations', [Platform::class, 'organizations']);
    Route::get('organizations/{organization}', [Platform::class, 'organization']);
    Route::patch('organizations/{organization}', [Platform::class, 'updateOrganization']);
    Route::post('organizations/{organization}/invoices', [Platform::class, 'issueInvoice']);
    Route::get('invoices', [Platform::class, 'invoices']);
    Route::post('invoices/{invoice}/pay', [Platform::class, 'payInvoice']);
    Route::post('invoices/{invoice}/void', [Platform::class, 'voidInvoice']);
    Route::get('plans', [Platform::class, 'plans']);
    Route::post('plans', [Platform::class, 'savePlan']);
    Route::put('plans/{plan}', [Platform::class, 'savePlan']);
});

Route::middleware(['auth:sanctum', 'tenant', 'branch'])->group(function () {
    // Academy account & subscription (open even when the subscription lapsed)
    Route::get('subscription', [Saas::class, 'subscription']);
    Route::middleware('role:admin')->group(function () {
        Route::put('subscription/account', [Saas::class, 'updateAccount']);
        Route::post('subscription/change', [Saas::class, 'changePlan']);
        Route::post('subscription/cancel', [Saas::class, 'cancel']);
        Route::post('subscription/resume', [Saas::class, 'resume']);
    });


    Route::get('lookups', [Catalog::class, 'lookups']);
    Route::get('dashboard/reception', [Insights::class, 'reception']);
    Route::get('alerts', [Insights::class, 'alerts']);
    Route::post('alerts/read', [Insights::class, 'readAlerts']);

    // 1.1 Leads & trials
    Route::get('leads', [Leads::class, 'index']);
    Route::post('leads', [Leads::class, 'store']);
    Route::get('leads/{lead}', [Leads::class, 'show']);
    Route::patch('leads/{lead}', [Leads::class, 'update']);
    Route::delete('leads/{lead}', [Leads::class, 'destroy']);
    Route::post('leads/{lead}/trials', [Leads::class, 'scheduleTrial']);
    Route::post('leads/{lead}/convert', [Families::class, 'convertLead']);
    Route::get('trials', [Leads::class, 'trials']);
    Route::patch('trials/{trial}', [Leads::class, 'updateTrial']);

    // 1.2 Families & clients
    Route::get('families', [Families::class, 'index']);
    Route::post('families', [Families::class, 'store']);
    Route::get('families/{family}', [Families::class, 'show']);
    Route::patch('families/{family}', [Families::class, 'update']);
    Route::post('families/{family}/clients', [Families::class, 'storeClient']);
    Route::post('families/{family}/credits', [Families::class, 'addCredit'])->middleware('role:manager,accountant');
    Route::get('clients', [Families::class, 'clients']);
    Route::get('clients/{client}', [Families::class, 'client']);
    Route::patch('clients/{client}', [Families::class, 'updateClient']);
    Route::post('clients/{client}/wallet', [Families::class, 'topUp'])->middleware('role:manager,accountant,reception');
    Route::post('clients/{client}/verification', [Families::class, 'sendVerification']);

    // 2.1 Registration & enrolment, waiting list
    Route::get('registrations', [Enrolment::class, 'index']);
    Route::post('registrations', [Enrolment::class, 'store']);
    Route::post('registrations/{registration}/cancel', [Enrolment::class, 'cancel']);
    Route::get('package-sessions', [Enrolment::class, 'sessionPackages']);
    Route::get('registrations/{registration}/sessions', [Enrolment::class, 'sessions']);
    Route::post('registrations/{registration}/sessions', [Enrolment::class, 'logSession']);
    Route::delete('package-sessions/{packageSession}', [Enrolment::class, 'deleteSession']);
    Route::get('waiting-list', [Enrolment::class, 'waitingList']);
    Route::post('waiting-list', [Enrolment::class, 'joinWaitingList']);
    Route::post('waiting-list/{entry}/respond', [Enrolment::class, 'respond']);

    // 2.2 Courts, calendar, reservations
    Route::get('calendar', [Schedule::class, 'events']);
    Route::get('availability', [Schedule::class, 'availability']);
    Route::post('sessions/generate', [Schedule::class, 'generate']);
    Route::patch('sessions/{session}', [Schedule::class, 'updateSession']);
    Route::get('reservations', [Schedule::class, 'reservations']);
    Route::get('reservations/quote', [Schedule::class, 'quote']);
    Route::post('reservations', [Schedule::class, 'storeReservation']);
    Route::patch('reservations/{reservation}', [Schedule::class, 'updateReservation']);

    // Tournaments
    Route::get('tournaments', [Tournaments::class, 'index'])->middleware('feature:tournaments');
    Route::get('tournaments/{tournament}', [Tournaments::class, 'show'])->middleware('feature:tournaments');
    Route::middleware('role:manager,reception,coach')->group(function () {
        Route::post('tournament-matches/{match}/result', [Tournaments::class, 'result'])->middleware('feature:tournaments');
        Route::delete('tournament-matches/{match}/result', [Tournaments::class, 'clearResult'])->middleware('feature:tournaments');
    });
    Route::middleware('role:manager,reception')->group(function () {
        Route::post('tournaments', [Tournaments::class, 'save'])->middleware('feature:tournaments');
        Route::put('tournaments/{tournament}', [Tournaments::class, 'save'])->middleware('feature:tournaments');
        Route::post('tournaments/{tournament}/teams', [Tournaments::class, 'addTeam'])->middleware('feature:tournaments');
        Route::patch('tournament-teams/{entry}', [Tournaments::class, 'updateTeam'])->middleware('feature:tournaments');
        Route::delete('tournament-teams/{entry}', [Tournaments::class, 'removeTeam'])->middleware('feature:tournaments');
        Route::post('tournament-teams/{entry}/invoice', [Tournaments::class, 'invoiceEntry'])->middleware('feature:tournaments');
        Route::post('tournaments/{tournament}/fixtures', [Tournaments::class, 'generate'])->middleware('feature:tournaments');
        Route::post('tournaments/{tournament}/knockout', [Tournaments::class, 'knockout'])->middleware('feature:tournaments');
        Route::post('tournaments/{tournament}/schedule', [Tournaments::class, 'schedule'])->middleware('feature:tournaments');
        Route::patch('tournament-matches/{match}', [Tournaments::class, 'updateMatch'])->middleware('feature:tournaments');
    });

    // 2.3 Billing, POS, inventory, expenses
    Route::get('invoices', [Finance::class, 'invoices']);
    Route::post('invoices', [Finance::class, 'storeInvoice'])->middleware('role:manager,accountant,reception');
    Route::get('invoices/{invoice}', [Finance::class, 'invoice']);
    Route::post('invoices/{invoice}/payments', [Finance::class, 'pay']);
    Route::post('invoices/{invoice}/void', [Finance::class, 'void'])->middleware('role:manager,accountant');
    Route::get('products', [Finance::class, 'products'])->middleware('feature:pos');
    Route::get('products/lookup', [Finance::class, 'lookup'])->middleware('feature:pos');
    Route::post('products', [Finance::class, 'saveProduct'])->middleware(['role:manager', 'feature:pos']);
    Route::put('products/{product}', [Finance::class, 'saveProduct'])->middleware(['role:manager', 'feature:pos']);
    Route::post('inventory/adjust', [Finance::class, 'adjustStock'])->middleware(['role:manager,reception', 'feature:pos']);
    Route::get('inventory/movements', [Finance::class, 'movements'])->middleware('feature:pos');
    Route::get('sales', [Finance::class, 'sales'])->middleware('feature:pos');
    Route::post('sales', [Finance::class, 'checkout'])->middleware('feature:pos');
    Route::get('expenses', [Finance::class, 'expenses']);
    Route::post('expenses', [Finance::class, 'saveExpense']);
    Route::put('expenses/{expense}', [Finance::class, 'saveExpense'])->middleware('role:manager,accountant');
    Route::post('expenses/{expense}/status', [Finance::class, 'expenseStatus'])->middleware('role:manager,accountant');

    // 2.4 Attendance, tasks, HR geofencing
    Route::get('sessions', [Ops::class, 'sessions']);
    Route::get('sessions/{session}/attendance', [Ops::class, 'sheet']);
    Route::post('sessions/{session}/attendance', [Ops::class, 'mark']);
    Route::get('tasks', [Ops::class, 'tasks']);
    Route::post('tasks', [Ops::class, 'saveTask']);
    Route::patch('tasks/{task}', [Ops::class, 'saveTask']);
    Route::post('hr/clock', [Ops::class, 'clock']);
    Route::get('hr/attendance', [Ops::class, 'staffAttendance']);
    Route::post('hr/attendance/{record}/review', [Ops::class, 'review'])->middleware('role:manager');

    // 3.2 Audit, payroll, communication logs · 3.3 analytics
    Route::middleware('role:manager,accountant')->group(function () {
        Route::get('payroll', [Insights::class, 'payroll'])->middleware('feature:payroll');
        Route::post('payroll/generate', [Insights::class, 'generatePayroll'])->middleware('feature:payroll');
        Route::post('payroll/{payroll}/payments', [Insights::class, 'payPayroll'])->middleware('feature:payroll');
        Route::get('logs/audit', [Insights::class, 'auditLogs']);
        Route::get('reports/financial', [Insights::class, 'financial'])->middleware('feature:reports');
        Route::get('reports/retention', [Insights::class, 'retention'])->middleware('feature:reports');
        Route::get('reports/funnel', [Insights::class, 'funnel'])->middleware('feature:reports');
    });
    Route::get('logs/communications', [Insights::class, 'communicationLogs']);
    Route::get('logs/journey', [Insights::class, 'journeys']);

    // Setup
    Route::get('locations', [Catalog::class, 'locations']);
    Route::get('branches', [Branches::class, 'index'])->middleware('role:manager,accountant');
    Route::get('courts', [Catalog::class, 'courts']);
    Route::get('teams', [Catalog::class, 'teams']);
    Route::get('packages', [Catalog::class, 'packages']);
    Route::middleware('role:manager')->group(function () {
        Route::post('locations', [Branches::class, 'save']);
        Route::put('locations/{location}', [Branches::class, 'save']);
        Route::post('branches', [Branches::class, 'save']);
        Route::put('branches/{location}', [Branches::class, 'save']);
        Route::post('courts', [Catalog::class, 'saveCourt']);
        Route::put('courts/{court}', [Catalog::class, 'saveCourt']);
        Route::post('courts/{court}/split', [Catalog::class, 'splitCourt']);
        Route::put('courts/{court}/rates', [Catalog::class, 'saveRates']);
        Route::post('teams', [Catalog::class, 'saveTeam']);
        Route::put('teams/{team}', [Catalog::class, 'saveTeam']);
        Route::post('packages', [Catalog::class, 'savePackage']);
        Route::put('packages/{package}', [Catalog::class, 'savePackage']);
        Route::get('staff', [Catalog::class, 'staff']);
        Route::post('staff', [Catalog::class, 'saveStaff']);
        Route::put('staff/{user}', [Catalog::class, 'saveStaff']);
        Route::get('settings', [Catalog::class, 'settings']);
        Route::put('settings', [Catalog::class, 'saveSettings']);
    });
});
