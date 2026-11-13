<?php

use App\Models\Lead;
use App\Models\Registration;
use App\Models\Trial;
use App\Models\User;
use App\Services\Hub;
use App\Services\Messenger;
use App\Services\Scheduling;
use App\Services\Subscription;
use App\Services\Tenant;
use App\Services\WaitingList;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Automated jobs. In production run `php artisan schedule:work` (or a cron calling
| `php artisan schedule:run` every minute).
*/

Artisan::command('hub:trial-reminders', function () {
    // Runs for every academy that is allowed to operate.
    Tenant::each(function () {
        // Reminder 1 day before the trial date (the first one is sent on scheduling).
        $trials = Trial::with('lead')->where('status', 'scheduled')->whereNull('day_before_reminder_sent_at')
            ->whereDate('scheduled_at', today()->addDay())->get();
        foreach ($trials as $t) {
            Messenger::notify($t->lead, 'trial_reminder', 'Trial reminder', "Reminder: the trial session".($t->lead->participant_name ? " for {$t->lead->participant_name}" : '').' is tomorrow at '.$t->scheduled_at->format('H:i').'. See you at '.Tenant::name().'!');
            $t->update(['day_before_reminder_sent_at' => now()]);
        }
        $this->info("Sent {$trials->count()} trial reminders.");
    });
})->purpose('Send day-before trial reminders');

Artisan::command('hub:daily-conversion', function () {
    // Runs for every academy that is allowed to operate.
    Tenant::each(function () {
        $pending = Lead::where('status', 'trial_approved')->get();
        if ($pending->isEmpty()) {
            return;
        }
        $names = $pending->take(10)->map->full_name->implode(', ');
        Hub::alert('conversion', "{$pending->count()} approved trials waiting to be converted", $names, '/leads?status=trial_approved', null, 'reception');
        foreach (User::whereIn('role', ['admin', 'manager'])->where('active', true)->get() as $u) {
            Messenger::send('email', $u->email, "{$pending->count()} approved trial leads are waiting for registration: {$names}", 'Daily conversion reminder', 'daily_conversion');
        }
        $this->info("Conversion reminder for {$pending->count()} leads.");
    });
})->purpose('Daily alert to convert approved trials into active clients');

Artisan::command('hub:waitlist', function () {
    // Runs for every academy that is allowed to operate.
    Tenant::each(function () {
        $expired = WaitingList::expireOffers();
        $this->info("Expired {$expired} offers.");
    });
})->purpose('Expire waiting list offers and pass spots to the next candidate');

Artisan::command('hub:sessions', function () {
    // Runs for every academy that is allowed to operate.
    Tenant::each(function () {
        $this->info('Created '.Scheduling::generateSessions(28).' sessions.');
        // Registrations that ran out become "ended" (used by churn / retention).
        $ended = Registration::where('status', 'active')->whereDate('end_date', '<', today())->get();
        foreach ($ended as $r) {
            $r->update(['status' => 'ended']);
            if ($r->team) {
                WaitingList::process($r->team);
            }
        }
    });
})->purpose('Generate upcoming team sessions and close ended registrations');

Artisan::command('saas:billing', function () {
    ['issued' => $i, 'changed' => $c] = Subscription::nightly();
    $this->info("Issued {$i} renewal invoices, updated {$c} academy statuses.");
})->purpose('SaaS: renewal invoices and trial / past-due / suspension status');

Schedule::command('hub:trial-reminders')->hourly();
Schedule::command('saas:billing')->dailyAt('02:00');
Schedule::command('hub:daily-conversion')->dailyAt('08:30');
Schedule::command('hub:waitlist')->everyFifteenMinutes();
Schedule::command('hub:sessions')->dailyAt('01:00');
