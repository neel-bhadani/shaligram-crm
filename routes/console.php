<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Automation
|--------------------------------------------------------------------------
|
| Event triggers need nothing here — they fire from LeadFollowUpService as
| leads actually move. This is for the two triggers that are questions rather
| than events ("is this follow-up three days late yet?") and for the built-in
| alerts, neither of which anything would ever notice on its own.
|
| Hourly, and hourly is the point: overdue is measured in days, so asking more
| often would find the same leads and asking once a day would let a lead go
| stale until tomorrow morning. withoutOverlapping() because a run over a large
| database can outlast the hour, and two of them at once would double every
| round-robin assignment.
|
| The schedule needs `php artisan schedule:run` on a cron, once a minute, on
| the server. Without it nothing here runs — and nothing else breaks, which is
| the trap: event rules go on working perfectly and the time ones simply never
| fire. The Automation page says so on the Rules tab, next to any rule with a
| time trigger.
*/
Schedule::command('automation:run')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('leads:prune-imports')->hourly()->withoutOverlapping();
