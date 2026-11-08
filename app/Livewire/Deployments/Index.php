<?php

namespace App\Livewire\Deployments;

use App\Models\Deployment;
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

        return view('livewire.deployments.index', [
            'deployments' => $query->paginate(50),
            'hasActive' => $hasActive,
        ]);
    }
}
