<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\Client;
use App\Models\Court;
use App\Models\Expense;
use App\Models\Family;
use App\Models\FollowUpTask;
use App\Models\InventoryItem;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SubscriptionInvoice;
use App\Models\Package;
use App\Models\PackageSession;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Registration;
use App\Models\Reservation;
use App\Models\StaffAttendance;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\Trial;
use App\Models\User;
use App\Models\WaitingListEntry;
use App\Services\Billing;
use App\Services\Hub;
use App\Services\PayrollService;
use App\Services\Pricing;
use App\Services\Scheduling;
use App\Services\Subscription;
use App\Services\Tenant;
use App\Services\Tournaments;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo data for Knock Academy. All staff logins use the password "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(42);

        // ---- SaaS platform: plans, the platform owner account, and the Knock Academy tenant ----
        $plans = collect([
            ['code' => 'starter', 'name' => 'Starter', 'tagline' => 'One venue getting organised', 'price_monthly' => 29, 'price_yearly' => 290, 'max_branches' => 1, 'max_staff' => 5, 'max_clients' => 150, 'features' => [], 'sort' => 1],
            ['code' => 'growth', 'name' => 'Growth', 'tagline' => 'Growing academies with a shop and events', 'price_monthly' => 79, 'price_yearly' => 790, 'max_branches' => 3, 'max_staff' => 20, 'max_clients' => 1000, 'features' => ['pos', 'tournaments', 'reports', 'branches'], 'sort' => 2],
            ['code' => 'pro', 'name' => 'Pro', 'tagline' => 'Multi-branch academies, everything included', 'price_monthly' => 149, 'price_yearly' => 1490, 'max_branches' => null, 'max_staff' => null, 'max_clients' => null, 'features' => array_keys(Plan::FEATURES), 'sort' => 3],
        ])->mapWithKeys(fn ($p) => [$p['code'] => Plan::create($p + ['trial_days' => 14])]);
        User::create(['name' => 'Knock Services Platform', 'email' => 'platform@knock.test', 'password' => 'password', 'role' => 'platform', 'pay_type' => 'monthly', 'pay_rate' => 0]);

        $knock = Organization::create([
            'name' => 'Knock Academy', 'slug' => 'knock-academy', 'plan_id' => $plans['pro']->id, 'billing_cycle' => 'monthly', 'status' => 'active',
            'current_period_end' => today()->startOfMonth()->addMonth()->subDay()->endOfDay(), 'owner_name' => 'Academy Admin', 'owner_email' => 'admin@knock.test',
            'phone' => '+961 1 740 100', 'country' => 'Lebanon', 'currency' => 'USD', 'timezone' => 'Asia/Beirut', 'brand_color' => '#1788c2', 'created_at' => now()->subMonths(5)->startOfMonth()->addDays(3),
        ]);
        for ($m = 5; $m >= 0; $m--) {
            $start = today()->startOfMonth()->subMonths($m);
            SubscriptionInvoice::create(['organization_id' => $knock->id, 'number' => Hub::sequence('subscription_invoices', 'number', 'SUB-'.now()->format('y').'-', 4), 'plan_id' => $plans['pro']->id,
                'amount' => 149, 'period_start' => $start, 'period_end' => $start->copy()->addMonth()->subDay(), 'due_date' => $start->copy()->addDays(7), 'kind' => $m === 5 ? 'first' : 'renewal',
                'status' => 'paid', 'paid_at' => $start->copy()->addDays(mt_rand(1, 5))->setTime(11, 0), 'method' => ['transfer', 'whish', 'omt'][$m % 3], 'reference' => 'TRX-'.mt_rand(100000, 999999)]);
        }
        Tenant::set($knock);
        Hub::setSetting('clients.prefix', 'KNA-');

        $manara = Location::create(['code' => 'BR-01', 'name' => 'Manara Complex', 'address' => 'Manara, Beirut', 'phone' => '+961 1 740 100', 'email' => 'manara@knock.test', 'opens_at' => '07:00', 'closes_at' => '23:00', 'latitude' => 33.8987, 'longitude' => 35.4823, 'geofence_radius_m' => 200]);
        $hazmieh = Location::create(['code' => 'BR-02', 'name' => 'Hazmieh Branch', 'address' => 'Hazmieh, Baabda', 'phone' => '+961 5 450 200', 'email' => 'hazmieh@knock.test', 'opens_at' => '15:00', 'closes_at' => '22:00', 'latitude' => 33.8547, 'longitude' => 35.5381, 'geofence_radius_m' => 150]);

        $staff = fn ($name, $email, $role, $loc, $type, $rate) => User::create([
            'name' => $name, 'email' => $email, 'password' => 'password', 'role' => $role, 'location_id' => $loc->id,
            'pay_type' => $type, 'pay_rate' => $rate, 'phone' => '+961 3 '.mt_rand(100000, 999999),
        ]);
        $admin = $staff('Academy Admin', 'admin@knock.test', 'admin', $manara, 'monthly', 0);
        $manager = $staff('Rami Haddad', 'manager@knock.test', 'manager', $manara, 'monthly', 1100);
        $reception = $staff('Maya Khoury', 'reception@knock.test', 'reception', $manara, 'monthly', 550);
        $accountant = $staff('Nadim Saab', 'accountant@knock.test', 'accountant', $manara, 'monthly', 600);
        $coachA = $staff('Coach Karim Nassar', 'karim@knock.test', 'coach', $manara, 'hourly', 12);
        $coachB = $staff('Coach Lara Fakhoury', 'lara@knock.test', 'coach', $manara, 'daily', 30);
        $coachC = $staff('Coach Elie Matar', 'elie@knock.test', 'coach', $hazmieh, 'hourly', 10);
        $hazManager = $staff('Tony Rizk', 'tony@knock.test', 'manager', $hazmieh, 'monthly', 900);
        $staff('Joelle Saad', 'hazmieh.reception@knock.test', 'reception', $hazmieh, 'monthly', 500);
        $manara->update(['manager_id' => $manager->id]);
        $hazmieh->update(['manager_id' => $hazManager->id]);

        // Courts with sub-court hierarchy. Sub-court IDs carry their full court's ID (C-01 → C-01-A).
        // Duration tiers feed the pricing engine; the hourly rate prices any leftover minutes.
        $tiers = fn (Court $c, array $t) => collect($t)->each(fn ($price, $min) => $c->rates()->create(['duration_min' => $min, 'price' => $price]));
        $c1 = Court::create(['location_id' => $manara->id, 'code' => 'C-01', 'name' => 'Main Court', 'kind' => 'full', 'hourly_rate' => 80]);
        $tiers($c1, [60 => 80, 90 => 110, 120 => 140]);
        $c1a = Court::create(['location_id' => $manara->id, 'parent_id' => $c1->id, 'code' => 'C-01-A', 'name' => 'Main Court – A', 'kind' => 'half', 'hourly_rate' => 45]);
        $c1b = Court::create(['location_id' => $manara->id, 'parent_id' => $c1->id, 'code' => 'C-01-B', 'name' => 'Main Court – B', 'kind' => 'half', 'hourly_rate' => 45]);
        $tiers($c1a, [60 => 45, 90 => 60, 120 => 80]);
        $tiers($c1b, [60 => 45, 90 => 60, 120 => 80]);
        $c2 = Court::create(['location_id' => $manara->id, 'code' => 'C-02', 'name' => 'Training Court', 'kind' => 'full', 'hourly_rate' => 60]);
        $tiers($c2, [60 => 60, 90 => 85, 120 => 105]);
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $q) {
            $tiers(Court::create(['location_id' => $manara->id, 'parent_id' => $c2->id, 'code' => "C-02-$q", 'name' => "Training Court – $q", 'kind' => 'quarter', 'hourly_rate' => 20]), [60 => 20, 120 => 35]);
        }
        $c3 = Court::create(['location_id' => $hazmieh->id, 'code' => 'C-03', 'name' => 'Hazmieh Court', 'kind' => 'full', 'hourly_rate' => 50]);
        $tiers($c3, [60 => 50, 90 => 70, 120 => 90]);

        // Teams, schedules (weekday 0 = Sunday) and packages.
        $teamDefs = [
            ['U10 Mini Ballers', 'U10', $coachA, $c1a, 12, '#f97316', [[1, '16:00', '17:30'], [3, '16:00', '17:30']], 90],
            ['U12 Boys', 'U12', $coachA, $c1b, 14, '#ef4444', [[2, '17:00', '18:30'], [4, '17:00', '18:30']], 100],
            ['U14 Girls', 'U14', $coachB, $c2, 6, '#ec4899', [[1, '18:00', '19:30'], [3, '18:00', '19:30'], [6, '10:00', '11:30']], 110],
            ['U16 Elite', 'U16', $coachB, $c1, 15, '#8b5cf6', [[2, '19:00', '21:00'], [5, '19:00', '21:00']], 130],
            ['Adults Evening', 'Adult', $coachC, $c3, 20, '#0ea5e9', [[1, '20:00', '21:30'], [4, '20:00', '21:30']], 70],
        ];
        $teams = [];
        $packages = [];
        foreach ($teamDefs as [$name, $age, $coach, $court, $cap, $color, $slots, $price]) {
            $team = Team::create(['name' => $name, 'age_group' => $age, 'coach_id' => $coach->id, 'location_id' => $court->location_id, 'capacity' => $cap, 'color' => $color]);
            foreach ($slots as [$wd, $s, $e]) {
                $team->schedules()->create(['weekday' => $wd, 'start_time' => $s, 'end_time' => $e, 'court_id' => $court->id]);
            }
            $teams[] = $team;
            $packages[] = Package::create(['name' => "$name – Monthly", 'team_id' => $team->id, 'price' => $price, 'duration_months' => 1, 'sessions_per_week' => count($slots)]);
            Package::create(['name' => "$name – Term (3 months)", 'team_id' => $team->id, 'price' => round($price * 2.7), 'duration_months' => 3, 'sessions_per_week' => count($slots)]);
        }
        // Session-based (non-team) packages: a fixed number of sessions within a validity period.
        $private4 = Package::create(['name' => 'Private Coaching – 4 sessions', 'type' => 'sessions', 'price' => 160, 'duration_months' => 1, 'sessions_per_week' => 1, 'sessions_count' => 4, 'session_minutes' => 60]);
        $private10 = Package::create(['name' => 'Private Coaching – 10 sessions', 'type' => 'sessions', 'price' => 370, 'duration_months' => 3, 'sessions_per_week' => 1, 'sessions_count' => 10, 'session_minutes' => 60]);
        $clinic = Package::create(['name' => 'Shooting Clinic – 8 sessions', 'type' => 'sessions', 'price' => 120, 'duration_months' => 2, 'sessions_per_week' => 1, 'sessions_count' => 8, 'session_minutes' => 90]);

        // Families & clients.
        $surnames = ['Haddad', 'Khalil', 'Saad', 'Frem', 'Aoun', 'Gemayel', 'Najjar', 'Hobeika', 'Rizk', 'Salameh', 'Daher', 'Karam', 'Abou Jaoude', 'Chamoun'];
        $kids = ['Joe', 'Ralph', 'Karl', 'Maria', 'Yara', 'Chris', 'Anthony', 'Lea', 'Marc', 'Jad', 'Tia', 'Omar', 'Sami', 'Nour', 'Ziad', 'Mira', 'Adam', 'Celine'];
        $parents = ['Georges', 'Rita', 'Fadi', 'Nayla', 'Tony', 'Carla', 'Walid', 'Dina', 'Pierre', 'Hala', 'Samir', 'Rana', 'Michel', 'Joanna'];
        $children = [];
        foreach ($surnames as $i => $surname) {
            $family = Family::create(['code' => Hub::sequence('families', 'code', 'FAM-'), 'name' => "$surname Family", 'discount_percent' => [0, 0, 10, 0, 15, 0, 5][$i % 7]]);
            $type = $i >= 12 ? 'adult' : 'parent';
            $parent = $family->clients()->create([
                'code' => Hub::sequence('clients', 'code', 'KNA-'), 'type' => $type, 'first_name' => $parents[$i], 'last_name' => $surname,
                'phone' => '+961 3 '.mt_rand(100000, 999999), 'email' => strtolower($parents[$i]).'.'.strtolower(str_replace(' ', '', $surname)).'@example.com',
                'billing_flag' => true, 'wallet_balance' => [0, 50, 0, 120, 0, 0, 25][$i % 7], 'phone_verified_at' => now()->subMonths(3),
            ]);
            if ($type === 'adult') {
                $children[] = [$parent, $family, 4];
                continue;
            }
            $n = $i % 3 === 0 ? 2 : 1;
            for ($k = 0; $k < $n; $k++) {
                $kid = $family->clients()->create([
                    'code' => Hub::sequence('clients', 'code', 'KNA-'), 'type' => 'child', 'first_name' => $kids[($i * 2 + $k) % count($kids)], 'last_name' => $surname,
                    'date_of_birth' => today()->subYears(mt_rand(8, 16))->subDays(mt_rand(0, 300)), 'gender' => mt_rand(0, 1) ? 'male' : 'female',
                    'phone_verified_at' => now()->subMonths(3),
                ]);
                $children[] = [$kid, $family, ($i + $k) % 4];
            }
        }

        // Registrations: a prior month (for churn/retention) and the current month.
        $prevStart = today()->subMonth()->startOfMonth();
        $curStart = today()->startOfMonth();
        foreach ($children as $idx => [$client, $family, $teamIdx]) {
            $team = $teams[$teamIdx];
            $package = $packages[$teamIdx];
            $prev = Registration::create(['client_id' => $client->id, 'package_id' => $package->id, 'team_id' => $team->id, 'coach_id' => $team->coach_id,
                'price' => $package->price, 'start_date' => $prevStart, 'end_date' => $prevStart->copy()->endOfMonth(), 'status' => 'ended']);
            $inv = Billing::invoice($family, [['description' => "{$package->name} for {$client->full_name}", 'unit_price' => $package->price, 'itemable' => $prev]], 'registration', null, 7);
            $inv->update(['created_at' => $prevStart, 'due_date' => $prevStart->copy()->addDays(7)]);
            $prev->update(['invoice_id' => $inv->id]);
            $this->backdatedPayment($inv, $prevStart->copy()->addDays(mt_rand(0, 6)));

            if ($idx % 6 === 5) {
                continue; // churned — did not renew
            }
            $cur = Registration::create(['client_id' => $client->id, 'package_id' => $package->id, 'team_id' => $team->id, 'coach_id' => $team->coach_id,
                'price' => $package->price, 'start_date' => $curStart, 'end_date' => $curStart->copy()->endOfMonth(), 'status' => 'active', 'renewed_from_id' => $prev->id]);
            $inv = Billing::invoice($family, [['description' => "{$package->name} for {$client->full_name}", 'unit_price' => $package->price, 'itemable' => $cur]], 'registration', null, 7);
            $inv->update(['created_at' => $curStart, 'due_date' => $curStart->copy()->addDays(7)]);
            $cur->update(['invoice_id' => $inv->id]);
            if ($idx % 5 !== 2) {
                $this->backdatedPayment($inv, $curStart->copy()->addDays(mt_rand(0, min(6, today()->day - 1))));
            } // else: stays overdue → restriction engine demo
        }

        // Fill U14 Girls to capacity and add a waiting list.
        $u14 = $teams[2];
        $extraFamily = Family::create(['code' => Hub::sequence('families', 'code', 'FAM-'), 'name' => 'Mansour Family']);
        $extraParent = $extraFamily->clients()->create(['code' => Hub::sequence('clients', 'code', 'KNA-'), 'type' => 'parent', 'first_name' => 'Joelle', 'last_name' => 'Mansour', 'phone' => '+961 70 123456', 'billing_flag' => true]);
        while (Registration::where('team_id', $u14->id)->where('status', 'active')->count() < $u14->capacity) {
            $girl = $extraFamily->clients()->create(['code' => Hub::sequence('clients', 'code', 'KNA-'), 'type' => 'child', 'first_name' => $kids[mt_rand(0, count($kids) - 1)], 'last_name' => 'Mansour', 'gender' => 'female']);
            $reg = Registration::create(['client_id' => $girl->id, 'package_id' => $packages[2]->id, 'team_id' => $u14->id, 'coach_id' => $u14->coach_id, 'price' => $packages[2]->price, 'start_date' => $curStart, 'end_date' => $curStart->copy()->endOfMonth(), 'status' => 'active']);
            $inv = Billing::invoice($extraFamily, [['description' => "{$packages[2]->name} for {$girl->full_name}", 'unit_price' => $packages[2]->price, 'itemable' => $reg]], 'registration');
            $reg->update(['invoice_id' => $inv->id]);
            $this->backdatedPayment($inv, now()->subDays(2));
        }
        foreach (array_slice($children, 0, 3) as $n => [$client]) {
            if ($client->type === 'child') {
                $e = WaitingListEntry::create(['team_id' => $u14->id, 'client_id' => $client->id, 'status' => 'waiting']);
                $e->update(['created_at' => now()->subDays(10 - $n)]);
            }
        }
        Hub::journey($extraParent->id, 'registration', 'Family registered', [], $extraFamily->id);

        // Sessions: last 3 weeks (for attendance) + next 4 weeks.
        $this->generatePastSessions(21);
        Scheduling::generateSessions(28);

        // Attendance on past sessions, with some absences → follow-up tasks.
        foreach (\App\Models\TeamSession::where('starts_at', '<', now())->get() as $session) {
            $roster = Registration::where('team_id', $session->team_id)->whereDate('start_date', '<=', $session->starts_at)->whereDate('end_date', '>=', $session->starts_at)->pluck('client_id');
            foreach ($roster as $cid) {
                $r = mt_rand(1, 100);
                Attendance::create(['team_session_id' => $session->id, 'client_id' => $cid, 'status' => $r <= 82 ? 'present' : ($r <= 92 ? 'absent' : 'late'), 'marked_by' => $session->coach_id]);
            }
        }
        $absentees = Attendance::where('status', 'absent')->selectRaw('client_id, count(*) c')->groupBy('client_id')->having('c', '>=', 2)->pluck('client_id');
        foreach (Client::whereIn('id', $absentees)->get() as $c) {
            FollowUpTask::create(['type' => 'absence', 'title' => "Follow up with parent: {$c->full_name} missed 2 sessions", 'client_id' => $c->id, 'assigned_to' => $reception->id, 'due_date' => today()->addDay(), 'status' => 'pending']);
        }

        // Leads & trials across the funnel.
        $leadDefs = [
            ['Hiba', 'Karam', 'Ryan', 'web', 'trial_scheduled', 0, 'scheduled', null],
            ['Ghassan', 'Tannous', 'Elio', 'instagram', 'trial_scheduled', 0, 'scheduled', null],
            ['Rola', 'Sfeir', 'Tala', 'facebook', 'trial_scheduled', 1, 'scheduled', null],
            ['Ziad', 'Hayek', 'Charbel', 'walk_in', 'trial_approved', -2, 'approved', 8],
            ['Mona', 'Akl', 'Sara', 'whatsapp', 'trial_approved', -1, 'approved', 9],
            ['Jihad', 'Bou Khalil', 'Fares', 'web', 'trial_rejected', -4, 'rejected', 3],
            ['Lina', 'Moussa', null, 'instagram', 'new', null, null, null],
            ['Kamal', 'Zein', 'Hadi', 'facebook', 'new', null, null, null],
            ['Nicole', 'Abboud', 'Perla', 'web', 'new', null, null, null],
            ['Bassam', 'Chidiac', 'Rami', 'referral', 'trial_scheduled', 3, 'scheduled', null],
        ];
        foreach ($leadDefs as $i => [$fn, $ln, $kid, $src, $status, $day, $tStatus, $rating]) {
            $lead = Lead::create(['first_name' => $fn, 'last_name' => $ln, 'phone' => '+961 71 '.mt_rand(100000, 999999), 'email' => strtolower($fn).'@example.com',
                'participant_name' => $kid ? "$kid $ln" : null, 'participant_dob' => $kid ? today()->subYears(mt_rand(8, 14)) : null, 'source' => $src, 'status' => $status, 'created_by' => $reception->id]);
            $lead->update(['created_at' => now()->subDays(mt_rand(0, 25))]);
            Hub::journey(null, 'lead', "Lead captured via {$src}", [], null, $lead->id);
            if ($day !== null) {
                $at = today()->addDays($day)->setTime([15, 16, 17][$i % 3], 0);
                Trial::create(['lead_id' => $lead->id, 'scheduled_at' => $at, 'duration_min' => 60, 'coach_id' => [$coachA, $coachB][$i % 2]->id, 'court_id' => $c2->children()->skip($i % 4)->first()->id,
                    'team_id' => $teams[$i % 4]->id, 'status' => $tStatus, 'rating' => $rating, 'coach_notes' => $rating ? ($rating >= 7 ? 'Good footwork, coachable, strong attitude.' : 'Needs fundamentals first; suggest clinic.') : null,
                    'reminder_sent_at' => now(), 'evaluated_at' => $rating ? $at->copy()->addHour() : null, 'created_by' => $reception->id]);
                Hub::journey(null, 'trial', 'Trial scheduled for '.$at->format('d M Y H:i'), [], null, $lead->id);
            }
        }

        // Court reservations.
        foreach ([[0, 18, $c1a, 0], [1, 19, $c2, 1], [2, 20, $c1, 2], [-3, 18, $c3, 3], [4, 9, $c1, 4]] as [$d, $h, $court, $fi]) {
            $start = today()->addDays($d)->setTime($h, 0);
            $parent = Client::where('billing_flag', true)->skip($fi)->first();
            $minutes = [60, 90, 120][$fi % 3];
            if (Scheduling::conflicts($court->id, $start, $start->copy()->addMinutes($minutes))) {
                continue;
            }
            $q = Pricing::quote($court, $minutes, $parent->family);
            $res = Reservation::create(['court_id' => $court->id, 'client_id' => $parent->id, 'family_id' => $parent->family_id, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($minutes), 'duration_min' => $minutes,
                'price' => $q['price'], 'pricing_note' => $q['note'], 'discount_percent' => $q['discount_percent'], 'discount_amount' => $q['discount_amount'], 'total' => $q['total'], 'status' => 'confirmed', 'created_by' => $reception->id]);
            $inv = Billing::invoice($parent->family, [['description' => "Court reservation {$court->code} {$court->name} ({$minutes} min · {$q['note']})", 'unit_price' => $q['price'], 'itemable' => $res]], 'reservation', $q['discount_percent'], 0);
            $res->update(['invoice_id' => $inv->id]);
            if ($d < 0) {
                $this->backdatedPayment($inv, $start);
            }
        }

        // Session-based package registrations with logged sessions (private coaching / clinic).
        foreach ([[$private4, 3, 0, $coachA], [$private10, 7, 1, $coachB], [$clinic, 2, 2, $coachA], [$private4, 4, 3, $coachB]] as [$pkg, $usedTarget, $ci, $coach]) {
            $client = Client::where('type', 'child')->skip(10 + $ci)->first() ?? Client::where('type', 'child')->skip($ci)->first();
            $start = today()->subDays(20 + $ci * 3);
            $reg = Registration::create(['client_id' => $client->id, 'package_id' => $pkg->id, 'coach_id' => $coach->id, 'price' => $pkg->price,
                'start_date' => $start, 'end_date' => $start->copy()->addMonths($pkg->duration_months)->subDay(), 'sessions_total' => $pkg->sessions_count, 'status' => 'active', 'created_by' => $reception->id]);
            $inv = Billing::invoice($client->family, [['description' => "{$pkg->name} ({$pkg->sessions_count} sessions) for {$client->full_name}", 'unit_price' => $pkg->price, 'itemable' => $reg]], 'registration');
            $reg->update(['invoice_id' => $inv->id]);
            $this->backdatedPayment($inv, $start->copy()->setTime(17, 0));
            $logged = 0;
            for ($k = 0; $logged < $usedTarget; $k++) {
                $status = $k === 2 ? 'excused' : ($k === 4 ? 'no_show' : 'attended');
                PackageSession::create(['registration_id' => $reg->id, 'client_id' => $client->id, 'coach_id' => $coach->id, 'held_at' => $start->copy()->addDays(2 + $k * 4)->setTime(16, 0),
                    'duration_min' => $pkg->session_minutes, 'status' => $status, 'note' => $status === 'excused' ? 'Sick — parent called ahead' : ($k === 0 ? 'Assessment: work on left hand' : null), 'marked_by' => $coach->id]);
                $logged += in_array($status, PackageSession::COUNTED) ? 1 : 0;
            }
            $reg->update(['sessions_used' => $usedTarget, 'status' => $usedTarget >= $pkg->sessions_count ? 'ended' : 'active']);
            Hub::journey($client->id, 'enrolment', "Enrolled in {$pkg->name}", ['registration_id' => $reg->id], $client->family_id);
        }

        // Products & per-location inventory.
        $products = [
            ['KNA-JRS-H', '6221000000011', 'Knock Home Jersey', 'Apparel', 45, 18], ['KNA-JRS-A', '6221000000028', 'Knock Away Jersey', 'Apparel', 45, 18],
            ['KNA-SHT', '6221000000035', 'Training Shorts', 'Apparel', 25, 9], ['KNA-HOOD', '6221000000042', 'Academy Hoodie', 'Apparel', 55, 22],
            ['BALL-7', '6221000000059', 'Basketball Size 7', 'Equipment', 35, 15], ['BALL-5', '6221000000066', 'Basketball Size 5 (Kids)', 'Equipment', 28, 11],
            ['SOCK', '6221000000073', 'Performance Socks', 'Apparel', 8, 2.5], ['BOTTLE', '6221000000080', 'Water Bottle', 'Accessories', 10, 3],
            ['WATER', '6221000000097', 'Mineral Water 500ml', 'Canteen', 1, 0.3], ['ENERGY', '6221000000103', 'Sports Drink', 'Canteen', 2.5, 1],
        ];
        foreach ($products as [$sku, $bc, $name, $cat, $price, $cost]) {
            $p = Product::create(['sku' => $sku, 'barcode' => $bc, 'name' => $name, 'category' => $cat, 'price' => $price, 'cost' => $cost]);
            InventoryItem::create(['product_id' => $p->id, 'location_id' => $manara->id, 'quantity' => mt_rand(0, 40), 'reorder_level' => 5]);
            InventoryItem::create(['product_id' => $p->id, 'location_id' => $hazmieh->id, 'quantity' => mt_rand(0, 15), 'reorder_level' => 3]);
        }

        // Daily POS counter sales and court rentals over the last 45 days (for the financial charts).
        $walkIn = Family::firstOrCreate(['code' => 'WALK-IN'], ['name' => 'Walk-in customers']);
        $allProducts = Product::all();
        $bookable = [$c1, $c1a, $c1b, $c2, $c3];
        for ($d = 45; $d >= 1; $d--) {
            $day = today()->subDays($d);
            for ($n = mt_rand(1, 4); $n > 0; $n--) {
                $p = $allProducts->random();
                $qty = mt_rand(1, 3);
                $at = $day->copy()->setTime(mt_rand(15, 21), mt_rand(0, 59));
                $sale = \App\Models\Sale::create(['number' => Hub::sequence('sales', 'number', 'POS-'.$at->format('ymd').'-', 4), 'location_id' => $manara->id, 'total' => $p->price * $qty, 'method' => ['cash', 'card', 'whish'][mt_rand(0, 2)], 'created_by' => $reception->id]);
                $sale->items()->create(['product_id' => $p->id, 'quantity' => $qty, 'unit_price' => $p->price, 'total' => $p->price * $qty]);
                $inv = Billing::invoice($walkIn, [['description' => $p->name, 'quantity' => $qty, 'unit_price' => $p->price, 'itemable' => $p]], 'pos', 0, 0, "POS sale {$sale->number}", $sale->location_id);
                $inv->update(['created_at' => $at]);
                $sale->update(['invoice_id' => $inv->id, 'created_at' => $at]);
                $this->backdatedPayment($inv, $at, $sale->method);
            }
            if (mt_rand(0, 1)) {
                $court = $bookable[mt_rand(0, 4)];
                $start = $day->copy()->setTime(mt_rand(8, 13), 0);
                if (! Scheduling::conflicts($court->id, $start, $start->copy()->addHours(2))) {
                    $q = Pricing::quote($court, 120);
                    $res = Reservation::create(['court_id' => $court->id, 'contact_name' => ['Corporate league', 'Birthday party', 'Pickup group', 'School team'][mt_rand(0, 3)], 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(2), 'duration_min' => 120,
                        'price' => $q['price'], 'pricing_note' => $q['note'], 'total' => $q['price'], 'status' => 'confirmed', 'created_by' => $reception->id]);
                    $inv = Billing::invoice($walkIn, [['description' => "Court rental {$court->code} {$court->name} (120 min)", 'unit_price' => $q['price'], 'itemable' => $res]], 'reservation', 0, 0);
                    $res->update(['invoice_id' => $inv->id]);
                    $this->backdatedPayment($inv, $start);
                }
            }
        }

        // Expenses (last 60 days).
        $cats = ['Utilities' => [300, 600], 'Maintenance' => [80, 400], 'Equipment' => [100, 500], 'Marketing' => [50, 250], 'Rent' => [700, 700], 'Supplies' => [30, 120]];
        for ($d = 60; $d >= 0; $d -= mt_rand(2, 5)) {
            $cat = array_rand($cats);
            [$min, $max] = $cats[$cat];
            Expense::create(['location_id' => $manara->id, 'category' => $cat, 'description' => "$cat – ".today()->subDays($d)->format('M d'), 'amount' => mt_rand($min, $max),
                'expense_date' => today()->subDays($d), 'status' => $d > 7 ? 'paid' : ['pending', 'approved', 'paid'][mt_rand(0, 2)], 'created_by' => $accountant->id]);
        }

        // Staff attendance this month (geofencing), incl. records needing review.
        foreach ([$reception, $coachA, $coachB, $coachC, $manager] as $u) {
            $loc = $u->location;
            for ($date = today()->subMonth()->startOfMonth(); $date->lt(today()); $date = $date->copy()->addDay()) {
                $d = (int) $date->diffInDays(today());
                if ($date->isSunday()) {
                    continue;
                }
                $in = $date->copy()->setTime($u->role === 'coach' ? 15 : 9, mt_rand(0, 20));
                $far = mt_rand(1, 100) > 92;
                $lat = $loc->latitude + ($far ? 0.01 : mt_rand(-5, 5) / 10000);
                $dist = \App\Http\Controllers\Api\OperationsController::distanceMeters($lat, $loc->longitude, $loc->latitude, $loc->longitude);
                StaffAttendance::create(['user_id' => $u->id, 'location_id' => $loc->id, 'check_in_at' => $in, 'check_in_lat' => $lat, 'check_in_lng' => $loc->longitude, 'check_in_distance_m' => $dist,
                    'check_out_at' => $in->copy()->addHours($u->role === 'coach' ? mt_rand(3, 5) : 8), 'check_out_lat' => $loc->latitude, 'check_out_lng' => $loc->longitude, 'check_out_distance_m' => 0,
                    'status' => $far ? ($d > 5 ? ['approved', 'rejected'][mt_rand(0, 1)] : 'attention_required') : 'ok']);
            }
        }
        PayrollService::generate(today()->subMonth()->format('Y-m'));
        foreach (\App\Models\Payroll::all() as $i => $pr) {
            $amount = $i % 3 === 1 ? round($pr->earned * 0.6, 2) : $pr->earned; // some balances roll over
            if ($amount > 0) {
                \App\Models\PayrollPayment::create(['payroll_id' => $pr->id, 'amount' => $amount, 'paid_on' => today()->startOfMonth()->addDays(2), 'method' => 'transfer']);
                $pr->increment('paid', $amount);
            }
        }
        PayrollService::generate(today()->format('Y-m'));

        $this->seedTournaments($teams, $c1, $c2);
        // Demo leads: most enquire at Manara, every fourth at Hazmieh.
        Lead::whereNull('location_id')->orderBy('id')->get()->each(fn ($l, $i) => $l->update(['location_id' => $i % 4 === 3 ? $hazmieh->id : $manara->id]));

        Hub::alert('conversion', '2 approved trials waiting to be converted', 'Ziad Hayek, Mona Akl', '/leads?status=trial_approved', null, 'reception');

        Tenant::set(null);
        $this->seedOtherAcademies($plans);
    }

    /** More academies on the platform, in every subscription state, for the platform console. */
    private function seedOtherAcademies($plans): void
    {
        $defs = [
            // name, slug, owner, email, plan, cycle, status, created, extra dates, clients
            ['Hoops Lab', 'hoops-lab', 'Nadine Farah', 'nadine@hoopslab.test', 'growth', 'monthly', 'trialing', now()->subDays(10), ['trial_ends_at' => now()->addDays(4)->endOfDay()], 34],
            ['Cedars Sports Club', 'cedars-sc', 'Walid Gemayel', 'walid@cedars.test', 'growth', 'yearly', 'active', now()->subMonths(4), ['current_period_end' => now()->addMonths(8)->endOfDay()], 120],
            ['Jbeil Ballers', 'jbeil-ballers', 'Rita Sfeir', 'rita@jbeil.test', 'starter', 'monthly', 'active', now()->subMonths(3), ['current_period_end' => now()->subDays(3)->endOfDay()], 58],
            ['Tripoli Hoops', 'tripoli-hoops', 'Omar Kabbara', 'omar@tripoli.test', 'starter', 'monthly', 'trialing', now()->subDays(20), ['trial_ends_at' => now()->subDays(6)->endOfDay()], 12],
            ['Saida Youth Academy', 'saida-youth', 'Hiba Bizri', 'hiba@saida.test', 'starter', 'monthly', 'trialing', now()->subDays(2), ['trial_ends_at' => now()->addDays(12)->endOfDay()], 5],
        ];
        $first = ['Ali', 'Sara', 'Jad', 'Maya', 'Karim', 'Lea', 'Omar', 'Yara', 'Hadi', 'Rana'];
        $last = ['Khalil', 'Haddad', 'Nasr', 'Saliba', 'Fares', 'Aoun', 'Daou', 'Rahme'];
        foreach ($defs as [$name, $slug, $owner, $email, $plan, $cycle, $status, $created, $dates, $clients]) {
            ['organization' => $org] = Subscription::signUp(['academy_name' => $name, 'slug' => $slug, 'name' => $owner, 'email' => $email, 'password' => 'password',
                'plan' => $plan, 'billing_cycle' => $cycle, 'country' => 'Lebanon', 'branch_name' => 'Main court']);
            $org->update(['status' => $status, 'created_at' => $created] + $dates);
            Tenant::run($org, function () use ($clients, $first, $last) {
                for ($i = 0; $i < $clients; $i++) {
                    $fam = Family::create(['code' => Hub::sequence('families', 'code', 'FAM-'), 'name' => $last[$i % 8].' Family']);
                    $fam->clients()->create(['code' => Hub::sequence('clients', 'code', Hub::clientPrefix()), 'first_name' => $first[$i % 10], 'last_name' => $last[$i % 8], 'type' => 'adult', 'billing_flag' => true, 'phone' => '+961 70 '.mt_rand(100000, 999999)]);
                }
            });
            if ($status === 'active') {
                $p = $plans[$plan];
                $end = $org->current_period_end;
                $start = $cycle === 'yearly' ? $end->copy()->subYear() : $end->copy()->subMonth();
                SubscriptionInvoice::create(['organization_id' => $org->id, 'number' => Hub::sequence('subscription_invoices', 'number', 'SUB-'.now()->format('y').'-', 4), 'plan_id' => $p->id, 'billing_cycle' => $cycle,
                    'amount' => $p->price($cycle), 'period_start' => $start, 'period_end' => $end, 'due_date' => $start->copy()->addDays(7), 'kind' => 'renewal', 'status' => 'paid', 'paid_at' => $start->copy()->addDays(2), 'method' => 'transfer']);
            }
        }
        Subscription::nightly(); // Jbeil Ballers' renewal is unpaid → past due with an open invoice
        $hoops = Organization::where('slug', 'hoops-lab')->first();
        Subscription::issue($hoops, $plans['growth'], 'monthly', 'first');
    }

    /** Three demo tournaments: one finished knockout, one league mid-way, one upcoming group cup. */
    private function seedTournaments(array $teams, Court $c1, Court $c2): void
    {
        [$u10, $u12, $u14, $u16, $adults] = $teams;
        $make = fn (array $a, array $entries) => tap(Tournament::create($a + ['code' => Hub::sequence('tournaments', 'code', 'TRN-', 4), 'location_id' => $c1->location_id]), function ($t) use ($entries) {
            foreach ($entries as $i => $e) {
                $t->teams()->create((is_string($e) ? ['name' => $e] : ['name' => $e->name, 'team_id' => $e->id]) + [
                    'entry_fee' => is_string($e) ? $t->entry_fee : 0, 'seed' => $i < 4 ? $i + 1 : null,
                    'contact_name' => is_string($e) ? ['Rami Saade', 'Lea Khoury', 'Fadi Nassar', 'Joelle Abi Nader', 'Karim Daher', 'Nour Haddad', 'Elie Matar'][$i % 7] : null,
                    'contact_phone' => is_string($e) ? '+961 3 '.mt_rand(100, 999).' '.mt_rand(100, 999) : null,
                ]);
            }
            Tournaments::generate($t);
        });
        // Past games: lay them out back to back on one court on the given day.
        $placePast = function (Tournament $t, $matches, Carbon $day, Court $court) {
            $at = $day->copy()->setTime(10, 0);
            foreach ($matches as $m) {
                $m->update(['court_id' => $court->id, 'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes($t->match_minutes)]);
                $at->addMinutes($t->match_minutes + $t->break_minutes);
            }
        };
        $play = fn ($m) => Tournaments::recordResult($m->fresh(), $h = mt_rand(38, 78), $h === ($a = mt_rand(36, 76)) ? $a + 3 : $a);

        // 1. Finished knockout with byes.
        $summer = $make(['name' => 'Summer Slam Adults', 'age_group' => 'Adult', 'format' => 'knockout', 'starts_on' => today()->subDays(40), 'ends_on' => today()->subDays(40), 'entry_fee' => 150, 'match_minutes' => 50, 'break_minutes' => 10],
            [$adults, 'Beirut Ballers', 'Cedars Hoops', 'Jounieh Jets', 'Byblos Kings', 'Metn Monsters']);
        $at = today()->subDays(40)->setTime(10, 0);
        foreach ($summer->matches()->where('status', '!=', 'bye')->get() as $m) {
            $m->update(['court_id' => $c1->id, 'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes(50)]);
            $at->addMinutes(60);
            $play($m);
        }
        foreach ($summer->teams()->whereNull('team_id')->take(4)->get() as $e) {
            $inv = (new \App\Http\Controllers\Api\TournamentController)->invoiceEntry($e);
            Billing::pay($inv, $inv->total, 'cash');
        }

        // 2. League half-way: two weekends played, the rest on the court schedule.
        $league = $make(['name' => 'Autumn League U14', 'age_group' => 'U14', 'format' => 'league', 'starts_on' => today()->subDays(9), 'ends_on' => today()->addDays(12), 'entry_fee' => 80, 'match_minutes' => 60, 'break_minutes' => 15],
            [$u14, 'Hoops Club U14', 'Sagesse Juniors', 'Champville Girls', 'Antonine Academy']);
        foreach ($league->matches()->whereIn('round', [1, 2])->get()->groupBy('round') as $r => $ms) {
            $placePast($league, $ms, today()->subDays($r === 1 ? 9 : 2), $c2);
            $ms->each($play);
        }
        Tournaments::schedule($league->fresh(), [$c2->id], '10:00', '14:00', true, today()->addDays(4)->toDateString());
        foreach ($league->teams()->whereNull('team_id')->get() as $i => $e) {
            $inv = (new \App\Http\Controllers\Api\TournamentController)->invoiceEntry($e);
            if ($i < 3) {
                Billing::pay($inv, $inv->total, 'cash');
            }
        }

        // 3. Upcoming group cup: drawn and on the courts, no results yet.
        $saturday = today()->next(Carbon::SATURDAY);
        $cup = $make(['name' => 'Knock Winter Cup U12', 'age_group' => 'U12', 'format' => 'groups_knockout', 'starts_on' => $saturday, 'ends_on' => $saturday->copy()->addDay(), 'entry_fee' => 100, 'max_teams' => 8, 'match_minutes' => 40, 'break_minutes' => 20, 'group_count' => 2, 'advance_per_group' => 2,
            'notes' => 'FIBA mini-basketball rules. 4 × 10 min quarters. Each team brings a table official.'],
            [$u12, 'Hoops Club U12', 'Sagesse Mini', 'Ashrafieh Juniors', 'Homenetmen U12', 'Champville U12', 'Tripoli Tigers', 'Zahle Eagles']);
        Tournaments::schedule($cup->fresh(), [$c1->id, $c2->id], '09:00', '19:00', false);
        foreach ($cup->teams()->whereNull('team_id')->take(5)->get() as $e) {
            (new \App\Http\Controllers\Api\TournamentController)->invoiceEntry($e);
        }
    }

    private function backdatedPayment($invoice, Carbon $at, ?string $method = null): void
    {
        $payment = Billing::pay($invoice, $invoice->total, $method ?? ['cash', 'card', 'transfer'][mt_rand(0, 2)]);
        $payment->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function generatePastSessions(int $days): void
    {
        foreach (\App\Models\TeamSchedule::with('team')->get() as $s) {
            for ($d = $days; $d >= 1; $d--) {
                $date = today()->subDays($d);
                if ($date->dayOfWeek !== (int) $s->weekday) {
                    continue;
                }
                $start = Carbon::parse($date->toDateString().' '.$s->start_time);
                \App\Models\TeamSession::create(['team_id' => $s->team_id, 'team_schedule_id' => $s->id, 'court_id' => $s->court_id, 'coach_id' => $s->team->coach_id,
                    'starts_at' => $start, 'ends_at' => Carbon::parse($date->toDateString().' '.$s->end_time), 'original_starts_at' => $start, 'status' => 'scheduled']);
            }
        }
    }
}
