<?php

namespace App\Livewire\Deployments;

use App\Models\Deployment;
use App\Services\Catalog\Catalog;
use App\Services\Deploy\DeployFormService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Deployments')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = Deployment::query()->with('connection:id,name')->latest('id');
        if ($this->status === 'active') {
            $query->active();
        } elseif ($this->status !== '') {
            $query->where('status', $this->status);
        }
        $hasActive = Deployment::query()->active()->exists();

        // Without a filter PK95 Core shows three parts: running right now, the apps whose
        // container exists, and the history (failed, cancelled, removed).
        $grouped = $this->status === '' ? [
            'running' => Deployment::query()->with('connection:id,name')->active()->latest('id')->get(),
            'live' => Deployment::query()->with('connection:id,name')->where('status', 'success')->latest('id')->get(),
            'history' => Deployment::query()->with('connection:id,name')->whereNotIn('status', [...Deployment::ACTIVE, 'success'])
                ->latest('id')->paginate(50),
        ] : [];
        $steps = [...DeployFormService::STEPS, ...Deployment::REMOVE_STEPS];

        return view('livewire.deployments.index', [
            'deployments' => $query->paginate(50),
            'hasActive' => $hasActive,
            ...$grouped,
            'stepLabels' => array_column($steps, 1, 0),
            'stepOrder' => ['deploy' => array_column(DeployFormService::STEPS, 0), 'remove' => array_column(Deployment::REMOVE_STEPS, 0)],
            'icons' => collect(app(Catalog::class)->all())->map(fn ($app) => $app->iconPath !== null)->all(),
        ]);
    }
}
