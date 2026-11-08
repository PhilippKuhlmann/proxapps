<?php

namespace App\Livewire\Apps;

use App\Models\Deployment;
use App\Services\Catalog\Catalog;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('App-Katalog')]
class Index extends Component
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function render(Catalog $catalog)
    {
        $apps = $catalog->all();
        $q = mb_strtolower(trim($this->search));
        if ($q !== '') {
            $apps = array_values(array_filter($apps, fn ($a) => str_contains(
                mb_strtolower($a->name.' '.$a->summary.' '.$a->category.' '.implode(' ', $a->tags)), $q)));
        }

        return view('livewire.apps.index', [
            'apps' => $apps,
            'counts' => Deployment::query()->where('status', 'success')
                ->selectRaw('app_id, COUNT(*) AS n')->groupBy('app_id')->pluck('n', 'app_id'),
            'errors_' => $catalog->errors(),
        ]);
    }
}
