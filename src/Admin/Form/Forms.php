<?php

declare(strict_types=1);

namespace Gereq\Admin\Form;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Resource\AdminField;
use Gereq\Validation\ValidationRegistry;
use ReflectionClass;
use ReflectionProperty;
use Twig\Environment;
use Twig\Markup;

/**
 * Form helpers — required fields + FormGenerator render from entity attributes.
 */
final class Forms
{
    /** @var array<string, list<string>> */
    private array $entityCache = [];

    /** @var array<string, list<string>> */
    private array $rulesetCache = [];

    /** @var array<string, class-string> form name → entity class */
    private array $formEntities = [];

    /** @var array<class-string, true> */
    private array $indexedEntities = [];

    public function __construct(
        private readonly ValidationRegistry $validation,
        private readonly Environment $twig,
    ) {
    }

    /**
     * Explicit form → entity binding (also done via #[FormEntity] on the class).
     *
     * @param class-string $entityClass
     */
    public function entity(string $form, string $entityClass): self
    {
        $this->formEntities[$form] = $entityClass;
        $this->indexEntity($entityClass);

        return $this;
    }

    /**
     * Render form fields HTML from #[FormGenerator] + #[FormField] on an entity (or class).
     *
     * @param class-string|object $source
     * @param array{
     *   form?: string|null,
     *   exclude?: list<string>,
     *   cols?: int,
     *   values?: array<string, mixed>,
     *   only?: list<string>,
     *   group?: string|null,
     *   choices?: array<string, list<array{value: string, label: string}>>,
     *   fieldOptions?: array<string, array<string, mixed>>,
     * } $options
     */
    public function render(string|object $source, array $options = []): Markup
    {
        $html = $this->twig->render('ui/form_generator.html.twig', [
            'fields' => $this->fields($source, $options),
            'form' => $options['form'] ?? $this->generatorMeta($source)?->form,
            'cols' => (int) ($options['cols'] ?? $this->generatorMeta($source)?->cols ?? 2),
            'entity' => is_object($source) ? $source : null,
            'wrap' => $options['wrap'] ?? true,
        ]);

        return new Markup($html, 'UTF-8');
    }

    /**
     * @param class-string|object $source
     * @param array{
     *   form?: string|null,
     *   exclude?: list<string>,
     *   values?: array<string, mixed>,
     *   only?: list<string>,
     *   group?: string|null,
     *   choices?: array<string, list<array{value: string, label: string}>>,
     *   fieldOptions?: array<string, array<string, mixed>>,
     * } $options
     * @return list<GeneratedField>
     */
    public function fields(string|object $source, array $options = []): array
    {
        $class = is_object($source) ? $source::class : $source;
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('Entity class "%s" neexistuje.', $class));
        }

        $this->indexEntity($class);
        $meta = $this->generatorMeta($class);
        $exclude = array_values(array_unique([
            ...($meta?->exclude ?? ['id']),
            ...($options['exclude'] ?? []),
        ]));
        $only = $options['only'] ?? null;
        $groupFilter = $options['group'] ?? null;
        $values = $options['values'] ?? [];
        $choiceOverrides = $options['choices'] ?? [];
        $fieldOptionOverrides = $options['fieldOptions'] ?? [];
        $instance = is_object($source) ? $source : null;

        $reflection = new ReflectionClass($class);
        /** @var list<array{field: GeneratedField, index: int}> $rows */
        $rows = [];
        $index = 0;

        foreach ($reflection->getProperties() as $property) {
            $propName = $property->getName();
            if (in_array($propName, $exclude, true)) {
                continue;
            }

            $formField = $this->formFieldAttr($property);
            $adminField = $this->adminFieldAttr($property);
            if ($formField === null && $adminField === null) {
                continue;
            }

            $name = $this->fieldName($property);
            if (is_array($only) && $only !== [] && !in_array($propName, $only, true) && !in_array($name, $only, true)) {
                continue;
            }

            $group = $formField?->group ?? '';
            if (is_string($groupFilter) && $groupFilter !== '' && $group !== $groupFilter) {
                continue;
            }

            $type = $formField?->type ?? $adminField?->type ?? 'text';
            $label = $formField?->label !== null && $formField->label !== ''
                ? $formField->label
                : ($adminField?->label !== '' ? (string) $adminField?->label : $this->humanize($propName));
            $required = ($formField?->required ?? false) || ($adminField?->required ?? false);
            $value = $this->resolveValue($name, $values, $instance, $property);
            $choices = array_key_exists($name, $choiceOverrides)
                ? $this->normalizeChoices($choiceOverrides[$name])
                : $this->choicesFor($class, $formField);
            $extra = trim(($formField?->extra ?? '') . ' ' . (string) ($formField?->options['class'] ?? ''));
            $hint = $formField?->hint !== null && $formField->hint !== ''
                ? $formField->hint
                : (isset($formField->options['hint']) ? (string) $formField->options['hint'] : null);
            $attrs = $formField?->attrs ?? '';
            $order = $formField?->order ?? 0;
            $mergedOptions = array_merge(
                $formField?->options ?? [],
                is_array($fieldOptionOverrides[$name] ?? null) ? $fieldOptionOverrides[$name] : [],
            );

            $rows[] = [
                'index' => $index++,
                'field' => new GeneratedField(
                    name: $name,
                    property: $propName,
                    label: $label,
                    type: $type,
                    required: $required,
                    value: $value,
                    options: $mergedOptions,
                    choices: $choices,
                    extra: $extra,
                    hint: $hint,
                    group: $group,
                    attrs: $attrs,
                    order: $order,
                ),
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $byOrder = $a['field']->order <=> $b['field']->order;
            if ($byOrder !== 0) {
                return $byOrder;
            }

            return $a['index'] <=> $b['index'];
        });

        return array_map(static fn (array $row): GeneratedField => $row['field'], $rows);
    }

    /**
     * @param class-string|object|string $source Entity class/instance or validation ruleset name
     * @return list<string>
     */
    public function requiredFields(string|object $source): array
    {
        if (is_object($source)) {
            return $this->fromEntity($source::class);
        }

        if ($this->looksLikeClass($source)) {
            return $this->fromEntity($source);
        }

        return $this->forForm($source);
    }

    /**
     * @param class-string|object|string $source
     * @return array<string, true>
     */
    public function requiredMap(string|object $source): array
    {
        $map = [];
        foreach ($this->requiredFields($source) as $field) {
            $map[$field] = true;
        }

        return $map;
    }

    public function isRequired(string|object $source, string $field): bool
    {
        return isset($this->requiredMap($source)[$field]);
    }

    /**
     * @param array<string, mixed>|null $requiredFields
     * @param class-string|object|string|null $entity
     */
    public function resolveRequired(
        string $name,
        ?string $form = null,
        mixed $explicit = null,
        ?array $requiredFields = null,
        string|object|null $entity = null,
    ): bool {
        if ($explicit !== null) {
            return (bool) $explicit;
        }
        if (is_array($requiredFields) && array_key_exists($name, $requiredFields)) {
            return (bool) $requiredFields[$name];
        }
        if ($entity !== null) {
            return $this->isRequired($entity, $name);
        }
        if ($form !== null && $form !== '') {
            return $this->isRequired($form, $name);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function forForm(string $name): array
    {
        if (isset($this->rulesetCache[$name])) {
            return $this->rulesetCache[$name];
        }

        $fields = $this->fromRuleset($name);
        $entityClass = $this->formEntities[$name] ?? null;
        if ($entityClass !== null) {
            $fields = array_values(array_unique([...$fields, ...$this->fromEntity($entityClass)]));
        }

        $this->rulesetCache[$name] = $fields;

        return $fields;
    }

    /**
     * @param class-string $entityClass
     * @return list<string>
     */
    private function fromEntity(string $entityClass): array
    {
        $this->indexEntity($entityClass);

        if (isset($this->entityCache[$entityClass])) {
            return $this->entityCache[$entityClass];
        }

        if (!class_exists($entityClass)) {
            throw new \InvalidArgumentException(sprintf('Entity class "%s" neexistuje.', $entityClass));
        }

        $reflection = new ReflectionClass($entityClass);
        $fields = [];

        foreach ($reflection->getProperties() as $property) {
            if ($this->propertyRequired($property)) {
                $fields[] = $this->fieldName($property);
            }
        }

        $fields = array_values(array_unique($fields));
        $this->entityCache[$entityClass] = $fields;

        return $fields;
    }

    /**
     * @param class-string $entityClass
     */
    private function indexEntity(string $entityClass): void
    {
        if (isset($this->indexedEntities[$entityClass]) || !class_exists($entityClass)) {
            return;
        }
        $this->indexedEntities[$entityClass] = true;

        $reflection = new ReflectionClass($entityClass);
        foreach ($reflection->getAttributes(FormEntity::class) as $attr) {
            /** @var FormEntity $meta */
            $meta = $attr->newInstance();
            if ($meta->form !== '') {
                $this->formEntities[$meta->form] = $entityClass;
                unset($this->rulesetCache[$meta->form]);
            }
        }
        foreach ($reflection->getAttributes(FormGenerator::class) as $attr) {
            /** @var FormGenerator $gen */
            $gen = $attr->newInstance();
            if ($gen->form !== null && $gen->form !== '') {
                $this->formEntities[$gen->form] = $entityClass;
                unset($this->rulesetCache[$gen->form]);
            }
        }
    }

    /** @param class-string|object $source */
    private function generatorMeta(string|object $source): ?FormGenerator
    {
        $class = is_object($source) ? $source::class : $source;
        if (!class_exists($class)) {
            return null;
        }
        $attrs = (new ReflectionClass($class))->getAttributes(FormGenerator::class);
        if ($attrs === []) {
            return null;
        }

        /** @var FormGenerator $meta */
        $meta = $attrs[0]->newInstance();

        return $meta;
    }

    private function formFieldAttr(ReflectionProperty $property): ?FormField
    {
        $attrs = $property->getAttributes(FormField::class);
        if ($attrs === []) {
            return null;
        }

        /** @var FormField $field */
        $field = $attrs[0]->newInstance();

        return $field;
    }

    private function adminFieldAttr(ReflectionProperty $property): ?AdminField
    {
        $attrs = $property->getAttributes(AdminField::class);
        if ($attrs === []) {
            return null;
        }

        /** @var AdminField $field */
        $field = $attrs[0]->newInstance();

        return $field;
    }

    private function readValue(object $entity, ReflectionProperty $property): mixed
    {
        $name = $property->getName();
        foreach ([$name, 'get' . ucfirst($name), 'is' . ucfirst($name)] as $method) {
            if (method_exists($entity, $method)) {
                try {
                    return $entity->{$method}();
                } catch (\Throwable) {
                    break;
                }
            }
        }

        try {
            $property->setAccessible(true);

            return $property->getValue($entity);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function resolveValue(
        string $name,
        array $values,
        ?object $instance,
        ReflectionProperty $property,
    ): mixed {
        if (array_key_exists($name, $values)) {
            return $values[$name];
        }
        if ($instance !== null) {
            return $this->readValue($instance, $property);
        }
        if ($property->hasDefaultValue()) {
            return $property->getDefaultValue();
        }

        return null;
    }

    /**
     * @param class-string $class
     * @return list<array{value: string, label: string}>
     */
    private function choicesFor(string $class, ?FormField $formField): array
    {
        if ($formField === null) {
            return [];
        }

        if ($formField->choices !== []) {
            return $this->normalizeChoices($formField->choices);
        }

        $values = [];
        if ($formField->choicesFrom !== '') {
            $const = $class . '::' . $formField->choicesFrom;
            if (defined($const)) {
                $raw = constant($const);
                if (is_array($raw)) {
                    foreach ($raw as $item) {
                        if (is_string($item) || is_int($item) || is_float($item)) {
                            $values[] = (string) $item;
                        }
                    }
                }
            }
        }

        $out = [];
        if ($formField->emptyLabel !== '') {
            $out[] = [
                'value' => '',
                'label' => $formField->emptyLabel,
            ];
        }

        foreach ($values as $value) {
            $label = $formField->choiceLabel !== ''
                ? $formField->choiceLabel . $value
                : $value;
            $out[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{value?: string, label?: string}|string> $choices
     * @return list<array{value: string, label: string}>
     */
    private function normalizeChoices(array $choices): array
    {
        $out = [];
        foreach ($choices as $choice) {
            if (is_string($choice)) {
                $out[] = ['value' => $choice, 'label' => $choice];
                continue;
            }
            if (is_array($choice)) {
                $value = (string) ($choice['value'] ?? '');
                $out[] = [
                    'value' => $value,
                    'label' => (string) ($choice['label'] ?? $value),
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function fromRuleset(string $name): array
    {
        $fields = [];
        foreach ($this->validation->rulesFor($name) as $field => $rule) {
            if ($this->ruleRequires((string) $field, $rule)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    private function propertyRequired(ReflectionProperty $property): bool
    {
        $formField = $this->formFieldAttr($property);
        if ($formField?->required) {
            return true;
        }
        $adminField = $this->adminFieldAttr($property);

        return (bool) ($adminField?->required);
    }

    private function fieldName(ReflectionProperty $property): string
    {
        $formField = $this->formFieldAttr($property);
        if ($formField?->name !== null && $formField->name !== '') {
            return $formField->name;
        }

        foreach ($property->getAttributes(ORM\Column::class) as $attr) {
            /** @var ORM\Column $column */
            $column = $attr->newInstance();
            if (is_string($column->name) && $column->name !== '') {
                return $column->name;
            }
        }

        return $this->snake($property->getName());
    }

    private function ruleRequires(string $field, mixed $rule): bool
    {
        if ($field === '' || str_starts_with($field, '_')) {
            return false;
        }

        $parts = $this->ruleParts($rule);
        if ($parts === []) {
            return false;
        }

        $hasRequired = false;
        $hasNullable = false;
        foreach ($parts as $part) {
            $name = strtolower(explode(':', $part, 2)[0]);
            if ($name === 'required' || $name === 'accepted' || $name === 'present') {
                $hasRequired = true;
            }
            if ($name === 'nullable' || $name === 'sometimes') {
                $hasNullable = true;
            }
        }

        return $hasRequired && !$hasNullable;
    }

    /** @return list<string> */
    private function ruleParts(mixed $rule): array
    {
        if (is_string($rule)) {
            return array_values(array_filter(array_map('trim', explode('|', $rule))));
        }
        if (is_array($rule)) {
            $parts = [];
            foreach ($rule as $item) {
                if (is_string($item)) {
                    $parts[] = $item;
                }
            }

            return $parts;
        }

        return [];
    }

    private function looksLikeClass(string $source): bool
    {
        return str_contains($source, '\\') || class_exists($source);
    }

    private function snake(string $value): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $value) ?? $value;

        return strtolower($snake);
    }

    private function humanize(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $this->snake($value)));
    }
}
