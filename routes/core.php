<?php

use App\Http\Controllers\AppIconController;
use App\Http\Controllers\Core\BridgeController;
use App\Http\Middleware\CoreToken;
use App\Support\Core;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// PK95 Core (app protocol v1). Stateless, no session, no CSRF - Core's service token instead.

Route::get('/core/health', function () {
    abort_unless(Core::enabled(), 404);
    DB::select('select 1');

    return response()->json(['ok' => true]);
});

Route::middleware(CoreToken::class)->group(function () {
    Route::get('/core/manifest', fn () => response()->json(Core::manifest()));

    // Before PK95 Core backs up the volume: write the SQLite WAL into the database file.
    Route::post('/core/backup/prepare', function () {
        if (DB::connection()->getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() !== ':memory:') {
            DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');
        }

        return response()->noContent();
    });

    Route::post('/api/bridge/{component}', BridgeController::class)->where('component', '[a-z-]+');
    Route::get('/api/apps/{app}/icon', AppIconController::class);
});
