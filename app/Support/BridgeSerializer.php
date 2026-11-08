<?php

namespace App\Support;

use App\Livewire\Apps\Deploy;
use App\Livewire\Deployments\Show;
use App\Livewire\Settings\ProfileForm;
use App\Models\Deployment;
use App\Models\NetworkProfile;
use App\Models\UpdateRun;
use App\Services\Deploy\DeployFormService;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\ViewErrorBag;
use UnitEnum;

/**
 * Turns the data of a rendered Livewire view into JSON for PK95 Core. Models keep their
 * attributes and loaded relations plus the few computed values the views ask for.
 */
class BridgeSerializer
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function view(object $component, array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value instanceof ViewErrorBag || str_starts_with((string) $key, '__')) {
                continue;
            }
            $out[$key] = self::value($value);
        }
        // values the views compute through the component ($this->…)
        if ($component instanceof Show) {
            $out['stepStates'] = collect(DeployFormService::STEPS)
                ->mapWithKeys(fn (array $step) => [$step[0] => $component->stepState($step[0])])->all();
            $out['removeSteps'] = Deployment::REMOVE_STEPS;
            $out['removeStepStates'] = collect(Deployment::REMOVE_STEPS)
                ->mapWithKeys(fn (array $step) => [$step[0] => $component->removeStepState($step[0])])->all();
        }
        if ($component instanceof Deploy) {
            $out['templateChoices'] = self::value($component->templateChoices());
        }
        if ($component instanceof ProfileForm) {
            $out['patternPreview'] = $component->patternPreview();
        }

        return $out;
    }

    public static function value(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof Model => self::model($value),
            $value instanceof LengthAwarePaginator => [
                '__paginator' => true,
                'data' => self::value(collect($value->items())),
                'current_page' => $value->currentPage(),
                'last_page' => $value->lastPage(),
                'total' => $value->total(),
            ],
            $value instanceof Collection => $value->map(fn ($v) => self::value($v))->all(),
            is_array($value) => array_map(fn ($v) => self::value($v), $value),
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Closure => null,
            $value instanceof Arrayable => self::value($value->toArray()),
            $value instanceof \JsonSerializable => self::value($value->jsonSerialize()),
            is_object($value) => self::value(get_object_vars($value)),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function model(Model $model): array
    {
        $data = self::value($model->attributesToArray());
        foreach ($model->getRelations() as $name => $relation) {
            $data[$name] = self::value($relation);
        }
        if ($model instanceof Deployment) {
            $data['is_active'] = $model->isActive();
            $data['has_updates'] = $model->hasUpdates();
            $data['app_update_available'] = $model->appUpdateAvailable();
        }
        if ($model instanceof UpdateRun) {
            $data['is_active'] = $model->isActive();
            $data['status_label'] = UpdateRun::STATUS_LABELS[$model->status] ?? $model->status;
            $data['step_label'] = UpdateRun::stepLabel($model->step);
        }
        if ($model instanceof NetworkProfile && method_exists($model, 'prefix')) {
            $data['prefix'] = $model->prefix();
        }

        return $data;
    }
}
