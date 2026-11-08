<?php

use App\Http\Controllers\Auth\SetupController;
use App\Jobs\CheckUpdates;
use App\Support\Core;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Central updates: look for new app versions / Debian packages every 6 hours, and run the
// optional nightly update window (the command itself checks enabled + time).
Schedule::job(new CheckUpdates)->everySixHours()->name('proxapps-check-updates')->withoutOverlapping();
Schedule::command('proxapps:auto-update')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Docker entrypoint: while no account exists, create (or re-print) the one-time code the
// first-run page asks for; once an account exists, remove a left-over code file.
Artisan::command('proxapps:setup-token', function () {
    if (Core::enabled()) {
        return; // PK95 Core manages this instance: no own account, no setup code
    }
    $token = SetupController::ensureToken();
    if ($token !== null) {
        $this->line('==> Einrichtungscode: '.$token);
    }
})->purpose('Einrichtungscode für die Ersteinrichtung erzeugen');
