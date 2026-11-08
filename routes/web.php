<?php

use App\Http\Controllers\AppIconController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\ProfileController;
use App\Livewire\Apps\Deploy;
use App\Livewire\Apps\Index as AppsIndex;
use App\Livewire\Connections\Form as ConnectionForm;
use App\Livewire\Connections\Index as ConnectionsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Deployments\Index as DeploymentsIndex;
use App\Livewire\Deployments\Show as DeploymentShow;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Settings\ProfileForm;
use App\Livewire\Updates\Index as UpdatesIndex;
use Illuminate\Support\Facades\Route;

// --- first run + authentication ---------------------------------------------------
Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::livewire('/', Dashboard::class)->name('dashboard');

    Route::livewire('/connections', ConnectionsIndex::class)->name('connections.index');
    Route::livewire('/connections/new', ConnectionForm::class)->name('connections.create');
    Route::livewire('/connections/{connection}/edit', ConnectionForm::class)->name('connections.edit');

    Route::livewire('/apps', AppsIndex::class)->name('apps.index');
    Route::get('/apps/{app}/icon', AppIconController::class)->name('apps.icon');
    Route::livewire('/apps/{app}', Deploy::class)->name('apps.deploy');

    Route::livewire('/deployments', DeploymentsIndex::class)->name('deployments.index');
    Route::livewire('/deployments/{deployment}', DeploymentShow::class)->name('deployments.show');

    Route::livewire('/updates', UpdatesIndex::class)->name('updates.index');

    Route::livewire('/settings', SettingsIndex::class)->name('settings');
    Route::livewire('/settings/profiles/new', ProfileForm::class)->name('profiles.create');
    Route::livewire('/settings/profiles/{profile}/edit', ProfileForm::class)->name('profiles.edit');
});
