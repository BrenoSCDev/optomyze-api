<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('api-logs:prune --days=30')->daily();

// Catches signature requests whose completion webhook never arrived — a
// vendor outage, a deregistered callback, an endpoint that was down.
Schedule::command('signatures:reconcile')->hourly()->withoutOverlapping();
