<?php

namespace App\Console\Commands;

use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdateService;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs every minute from the scheduler. In the configured update window (once per day) it
 * checks every managed container and queues an update for those with pending updates.
 */
#[Signature('proxapps:auto-update {--force : Zeitfenster ignorieren und sofort ausführen}')]
#[Description('Automatisches Update-Fenster: prüft alle Container und aktualisiert sie bei Bedarf.')]
class AutoUpdate extends Command
{
    public function handle(UpdateChecker $checker, UpdateService $updates): int
    {
        $force = (bool) $this->option('force');
        if (! $force && ! Settings::get('auto_update_enabled')) {
            return self::SUCCESS;
        }
        $now = now()->setTimezone((string) (Settings::get('timezone') ?: 'Europe/Berlin'));
        if (! $force) {
            if ($now->format('H:i') !== (string) Settings::get('auto_update_time')) {
                return self::SUCCESS;
            }
            if (Settings::get('auto_update_last_run') === $now->toDateString()) {
                return self::SUCCESS;
            }
        }
        Settings::put('auto_update_last_run', $now->toDateString());

        $queued = 0;
        foreach ($updates->managed() as $d) {
            if ($updates->hasActiveRun($d)) {
                continue;
            }
            try {
                $checker->check($d);
            } catch (Throwable $e) {
                $this->warn("{$d->hostname}: {$e->getMessage()}");

                continue;
            }
            $d->refresh();
            if ($d->update_check_error === null && $d->hasUpdates() && $updates->queue($d, 'schedule')) {
                $queued++;
            }
        }
        $this->info("Update-Fenster: {$queued} Container zum Aktualisieren eingeplant.");

        return self::SUCCESS;
    }
}
