<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Livewire;
use App\Support\BridgeSerializer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

use function Livewire\store;

/**
 * POST /api/bridge/{component} - runs one of ProxApps' own Livewire components on behalf of
 * PK95 Core, which renders the result in its interface. Same component, same behaviour:
 *
 *   {"mount": {...args}, "query": {...}}        first request of a page
 *   {"state": {...}, "updated": "v.ip"}          a field changed (runs the updated hooks)
 *   {"state": {...}, "call": {"method": "suggestIp", "params": {...}}}
 *   {"state": {...}}                             render only
 *
 * Answer: {state, errors, view, redirect, notices}. Models in the state travel as
 * {"__model": id}, like Livewire itself keeps them.
 */
class BridgeController extends Controller
{
    /** @var array<string, class-string<Component>> */
    public const COMPONENTS = [
        'dashboard' => Livewire\Dashboard::class,
        'apps' => Livewire\Apps\Index::class,
        'deploy' => Livewire\Apps\Deploy::class,
        'connections' => Livewire\Connections\Index::class,
        'connection-form' => Livewire\Connections\Form::class,
        'connection-status' => Livewire\ConnectionStatus::class,
        'deployments' => Livewire\Deployments\Index::class,
        'deployment' => Livewire\Deployments\Show::class,
        'container-status' => Livewire\Deployments\ContainerStatus::class,
        'updates' => Livewire\Updates\Index::class,
        'settings' => Livewire\Settings\Index::class,
        'profile-form' => Livewire\Settings\ProfileForm::class,
    ];

    /** Lifecycle methods Core may not call directly. */
    private const NOT_CALLABLE = ['mount', 'render', 'hydrate', 'dehydrate', 'boot', 'booted', 'updated', 'updating', 'rendering', 'rendered'];

    public function __invoke(Request $request, string $component): JsonResponse
    {
        $class = self::COMPONENTS[$component] ?? null;
        abort_if($class === null, 404, 'Unbekannte Komponente');

        $input = $request->json()->all();
        if (! empty($input['query']) && is_array($input['query'])) {
            $request->query->add($input['query']);
        }

        $c = app($class);
        try {
            if (array_key_exists('mount', $input)) {
                $this->mount($c, (array) $input['mount']);
            } else {
                $this->hydrate($c, (array) ($input['state'] ?? []));
            }
            // Livewire keeps validation errors between requests until a component resets them.
            foreach ((array) ($input['errors'] ?? []) as $key => $messages) {
                foreach ((array) $messages as $message) {
                    $c->addError((string) $key, (string) $message);
                }
            }

            if (! empty($input['updated']) && is_string($input['updated'])) {
                $this->runUpdatedHooks($c, $input['updated']);
            }

            if (! empty($input['call']['method'])) {
                $method = (string) $input['call']['method'];
                abort_if(in_array($method, self::NOT_CALLABLE, true) || str_starts_with($method, 'updated')
                    || ! method_exists($c, $method) || ! (new \ReflectionMethod($c, $method))->isPublic(), 400, "Methode {$method} ist nicht aufrufbar");
                app()->call([$c, $method], (array) ($input['call']['params'] ?? []));
            }
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $c->addError($key, $message);
                }
            }
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => $e->getMessage() ?: class_basename($e)], 422);
        }

        $redirect = store($c)->get('redirect');
        $view = [];
        if (! $redirect) {
            $rendered = app()->call([$c, 'render']);
            $view = $rendered instanceof View ? BridgeSerializer::view($c, $rendered->getData()) : [];
            // Livewire hands public properties to the view on its own; models only travel
            // as {"__model": id} in the state, so their data goes along here.
            foreach ($this->publicProperties($c) as $prop) {
                if ($prop->isInitialized($c) && $prop->getValue($c) instanceof Model) {
                    $view[$prop->getName()] ??= BridgeSerializer::value($prop->getValue($c));
                }
            }
        }

        return response()->json([
            'state' => $this->dehydrate($c),
            'errors' => $c->getErrorBag()->toArray(),
            'view' => $view,
            'redirect' => $redirect ? (parse_url((string) $redirect, PHP_URL_PATH) ?: '/') : null,
            'notices' => $this->notices($c),
            // other Livewire events (log lines for the browser, refreshes of nested parts)
            'events' => collect((array) store($c)->get('dispatched', []))
                ->map(fn ($event) => $event->serialize())
                ->reject(fn (array $e) => ($e['name'] ?? '') === 'notice')
                ->values()->all(),
        ]);
    }

    private function mount(object $c, array $args): void
    {
        if (! method_exists($c, 'mount')) {
            return;
        }
        $params = [];
        foreach ((new \ReflectionMethod($c, 'mount'))->getParameters() as $param) {
            if (! array_key_exists($param->getName(), $args)) {
                continue;
            }
            $value = $args[$param->getName()];
            $type = $param->getType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                $value = $value === null ? null : $type->getName()::query()->findOrFail($value);
            }
            $params[$param->getName()] = $value;
        }
        app()->call([$c, 'mount'], $params);
    }

    private function hydrate(object $c, array $state): void
    {
        foreach ($this->publicProperties($c) as $prop) {
            $name = $prop->getName();
            if (! array_key_exists($name, $state)) {
                continue;
            }
            $value = $state[$name];
            $type = $prop->getType();
            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                $value = is_array($value) && isset($value['__model']) ? $type->getName()::query()->findOrFail($value['__model']) : null;
            }
            $c->{$name} = $value;
        }
    }

    /** Livewire calls updating*, updated* and updated() after a field change. */
    private function runUpdatedHooks(object $c, string $property): void
    {
        $root = Str::before($property, '.');
        $value = data_get($c, $property);
        $hook = 'updated'.Str::studly($root);
        if (method_exists($c, $hook)) {
            $key = Str::after($property, '.');
            app()->call([$c, $hook], $key !== $property ? ['value' => $value, 'key' => $key] : ['value' => $value]);
        }
        if (method_exists($c, 'updated')) {
            $c->updated($property, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dehydrate(object $c): array
    {
        $state = [];
        foreach ($this->publicProperties($c) as $prop) {
            if (! $prop->isInitialized($c)) {
                continue;
            }
            $value = $prop->getValue($c);
            $state[$prop->getName()] = $value instanceof Model ? ['__model' => $value->getKey()] : BridgeSerializer::value($value);
        }

        return $state;
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function publicProperties(object $c): array
    {
        return array_values(array_filter(
            (new ReflectionClass($c))->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $p) => ! $p->isStatic() && $p->getDeclaringClass()->getName() !== Component::class,
        ));
    }

    /**
     * Livewire "notice" events and session flashes become toasts in Core.
     *
     * @return list<array{kind: string, text: string}>
     */
    private function notices(object $c): array
    {
        $notices = [];
        foreach ((array) store($c)->get('dispatched', []) as $event) {
            $data = $event->serialize();
            if (($data['name'] ?? '') === 'notice') {
                $params = $data['params'] ?? [];
                $notices[] = ['kind' => (string) ($params['kind'] ?? 'success'), 'text' => (string) ($params['text'] ?? '')];
            }
        }
        foreach (['success', 'error'] as $kind) {
            if (session()->has($kind)) {
                $notices[] = ['kind' => $kind, 'text' => (string) session()->pull($kind)];
            }
        }

        return $notices;
    }
}
