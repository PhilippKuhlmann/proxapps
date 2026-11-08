<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Services\Catalog\Catalog;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
class Dashboard extends Component
{
    public function render(Catalog $catalog)
    {
        $counts = Deployment::query()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return view('livewire.dashboard', [
            'connections' => Connection::query()->orderBy('name')->get(),
            'profileCount' => NetworkProfile::query()->count(),
            'apps' => $catalog->all(),
            'counts' => $counts,
            'recent' => Deployment::query()->with('connection')->latest('id')->limit(8)->get(),
        ]);
    }
}
