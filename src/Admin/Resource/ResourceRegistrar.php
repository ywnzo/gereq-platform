<?php

declare(strict_types=1);

namespace Gereq\Admin\Resource;

use Gereq\Http\Router;
use ReflectionClass;

final class ResourceRegistrar
{
    /** @var array<string, ResourceDefinition> */
    private array $definitions = [];

    public function register(string $entityClass): void
    {
        $definition = $this->inspect($entityClass);
        $this->definitions[$definition->name] = $definition;
    }

    public function get(string $name): ResourceDefinition
    {
        if (!isset($this->definitions[$name])) {
            throw new \RuntimeException(sprintf('Resource "%s" není registrován.', $name));
        }

        return $this->definitions[$name];
    }

    /** @return array<string, ResourceDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    public function bindRoutes(Router $router): void
    {
        foreach ($this->definitions as $definition) {
            if ($definition->controller !== null) {
                continue;
            }

            $router->group('/admin/' . $definition->name, function (Router $routes) use ($definition): void {
                $routes->get('', [ResourceController::class, 'index'])
                    ->name('admin.' . $definition->name . '.index')
                    ->permission($definition->permission . '.view');
                $routes->get('/create', [ResourceController::class, 'create'])
                    ->name('admin.' . $definition->name . '.create')
                    ->permission($definition->permission . '.create');
                $routes->post('', [ResourceController::class, 'store'])
                    ->name('admin.' . $definition->name . '.store')
                    ->permission($definition->permission . '.create');
                $routes->get('/{id}', [ResourceController::class, 'show'])
                    ->name('admin.' . $definition->name . '.show')
                    ->permission($definition->permission . '.view');
                $routes->get('/{id}/edit', [ResourceController::class, 'edit'])
                    ->name('admin.' . $definition->name . '.edit')
                    ->permission($definition->permission . '.edit');
                $routes->post('/{id}', [ResourceController::class, 'update'])
                    ->name('admin.' . $definition->name . '.update')
                    ->permission($definition->permission . '.edit');
                $routes->post('/{id}/delete', [ResourceController::class, 'destroy'])
                    ->name('admin.' . $definition->name . '.destroy')
                    ->permission($definition->permission . '.delete');
            }, ['middleware' => ['auth']]);
        }
    }

    /**
     * @param class-string $entityClass
     */
    private function inspect(string $entityClass): ResourceDefinition
    {
        $reflection = new ReflectionClass($entityClass);
        $attributes = $reflection->getAttributes(AdminResource::class);
        if ($attributes === []) {
            throw new \InvalidArgumentException($entityClass . ' nemá atribut AdminResource.');
        }

        /** @var AdminResource $meta */
        $meta = $attributes[0]->newInstance();
        $fields = [];

        foreach ($reflection->getProperties() as $property) {
            $fieldAttrs = $property->getAttributes(AdminField::class);
            if ($fieldAttrs === []) {
                continue;
            }

            /** @var AdminField $field */
            $field = $fieldAttrs[0]->newInstance();
            $fields[] = new ResourceField(
                name: $property->getName(),
                type: $field->type,
                label: $field->label !== '' ? $field->label : $property->getName(),
                searchable: $field->searchable,
                sortable: $field->sortable,
                filterable: $field->filterable,
                required: $field->required,
                showOnIndex: $field->showOnIndex,
                showOnDetail: $field->showOnDetail,
                showOnForm: $field->showOnForm,
            );
        }

        return new ResourceDefinition(
            name: $meta->name,
            label: $meta->label,
            permission: $meta->permission,
            entityClass: $entityClass,
            icon: $meta->icon,
            singular: $meta->singular ?? $meta->label,
            controller: $meta->controller,
            fields: $fields,
        );
    }
}
