<?php

declare(strict_types=1);

namespace Modules\Cms\Application;

use Gereq\Cms\Cms;
use Gereq\Validation\ValidationException;
use Gereq\Validation\ValidationRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FormSchema
{
    public const CONTROLS = ['text', 'email', 'tel', 'date', 'textarea', 'select', 'checkbox', 'radio', 'consent', 'file'];
    public const RESERVED = ['website_url', 'website', 'gereq_visitor', 'gereq_pass_token', 'captcha_token', 'captcha_answer', '__proto__', 'constructor', 'prototype'];

    public function __construct(
        private readonly Cms $cms,
        private readonly ValidationRegistry $validation,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function registerValidation(ValidationRegistry $validation, TranslatorInterface $translator): void
    {
        $validation->ruleset('cms.form.submit', ['values' => 'required'], static function (array $data, array $errors, array $context) use ($translator): array {
            foreach ($context['fields'] ?? [] as $name => $field) {
                $value = $data['values'][$name] ?? '';
                $control = $field['control'];
                $invalid = $value === '' ? !empty($field['required']) : mb_strlen($value) > ($control === 'textarea' ? 5000 : 190);
                if ($value !== '') {
                    $invalid = $invalid || ($control === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL));
                    $invalid = $invalid || (in_array($control, ['select', 'radio'], true) && !in_array($value, \Modules\Cms\Application\FormSchema::options($field), true));
                    if (in_array($control, ['checkbox', 'consent'], true)) {
                        $invalid = $invalid || $value !== '1';
                    }
                    if ($control === 'date') {
                        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                        $invalid = $invalid || $date === false || $date->format('Y-m-d') !== $value;
                    }
                }
                if ($invalid) {
                    $errors[$name] = $translator->trans('cms.form.invalid_value');
                }
            }
            return $errors;
        });
    }

    /** @param array<string, mixed> $payload
     * @param callable(mixed): array $children
     * @return array<string, mixed>
     */
    public static function normalize(array $payload, callable $children): array
    {
        return [
            'schema_version' => 1,
            'field_mapping' => self::mapping($payload),
            'action' => substr(trim((string) ($payload['action'] ?? '')), 0, 120),
            'submit_label' => mb_substr(trim((string) ($payload['submit_label'] ?? '')), 0, 190),
            'success_message' => mb_substr(trim((string) ($payload['success_message'] ?? '')), 0, 1000),
            'input_bg_color' => BlockDesignNormalizer::normalizeColor((string) ($payload['input_bg_color'] ?? ''), BlockDesignNormalizer::bgTokens(), 'none'),
            'input_text_color' => BlockDesignNormalizer::normalizeColor((string) ($payload['input_text_color'] ?? ''), BlockDesignNormalizer::textTokens(), 'inherit'),
            'input_border_color' => BlockDesignNormalizer::normalizeColor((string) ($payload['input_border_color'] ?? ''), BlockDesignNormalizer::textTokens(), 'inherit'),
            'input_focus_color' => BlockDesignNormalizer::normalizeColor((string) ($payload['input_focus_color'] ?? ''), BlockDesignNormalizer::textTokens(), 'inherit'),
            'input_radius' => isset($payload['input_radius']) && $payload['input_radius'] !== '' ? max(0, min(100, (int) $payload['input_radius'])) : '',
            'input_padding' => isset($payload['input_padding']) && $payload['input_padding'] !== '' ? max(0, min(100, (int) $payload['input_padding'])) : '',
            'input_font_size' => isset($payload['input_font_size']) && $payload['input_font_size'] !== '' ? max(10, min(72, (int) $payload['input_font_size'])) : '',
            'submit_variant' => in_array($payload['submit_variant'] ?? '', ['primary', 'ghost'], true) ? $payload['submit_variant'] : '',
            'submit_align' => in_array($payload['submit_align'] ?? '', ['center', 'end'], true) ? $payload['submit_align'] : 'start',
            'submit_full' => in_array($payload['submit_full'] ?? 0, [1, '1', true], true) ? 1 : 0,
            'submit_bg_color' => BlockDesignNormalizer::normalizeColor((string) ($payload['submit_bg_color'] ?? ''), BlockDesignNormalizer::bgTokens(), 'none'),
            'submit_text_color' => BlockDesignNormalizer::normalizeColor((string) ($payload['submit_text_color'] ?? ''), BlockDesignNormalizer::textTokens(), 'inherit'),
            'submit_radius' => isset($payload['submit_radius']) && $payload['submit_radius'] !== '' ? max(0, min(100, (int) $payload['submit_radius'])) : '',
            'submit_padding' => isset($payload['submit_padding']) && $payload['submit_padding'] !== '' ? max(0, min(100, (int) $payload['submit_padding'])) : '',
            'error_summary' => in_array($payload['error_summary'] ?? 0, [1, '1', true], true) ? 1 : 0,
            'columns' => (int) ($payload['columns'] ?? 2) === 1 ? 1 : 2,
            'children' => $children($payload['children'] ?? []),
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function field(array $payload): array
    {
        return [
            'name' => substr(trim((string) ($payload['name'] ?? '')), 0, 80),
            'control' => ($payload['control'] ?? '') === 'consent' ? 'checkbox' : (in_array($payload['control'] ?? '', self::CONTROLS, true) ? $payload['control'] : 'text'),
            'label' => mb_substr(trim(str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags((string) ($payload['label'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 190),
            'help' => mb_substr(trim(strip_tags((string) ($payload['help'] ?? ''))), 0, 500),
            'placeholder' => mb_substr(trim(strip_tags((string) ($payload['placeholder'] ?? ''))), 0, 190),
            'required' => !empty($payload['required']) ? 1 : 0,
            'options' => mb_substr(trim((string) ($payload['options'] ?? '')), 0, 5000),
            'full' => !empty($payload['full']) ? 1 : 0,
            'options_layout' => ($payload['options_layout'] ?? '') === 'horizontal' ? 'horizontal' : 'vertical',
        ];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, array<string, mixed>>
     */
    public function fields(array $payload): array
    {
        $fields = [];
        $walk = function (array $blocks, int $depth = 0) use (&$walk, &$fields): void {
            if ($depth > 12 || count($blocks) > 100) {
                $this->invalid();
            }
            foreach ($blocks as $block) {
                if (!is_array($block) || !is_array($block['payload'] ?? null)) {
                    $this->invalid();
                }
                $p = $block['payload'];
                if (!in_array($block['type'] ?? '', ['form_field', 'layout', 'text', 'heading', 'spacer', 'divider'], true)) {
                    $this->invalid();
                }
                if (($block['type'] ?? '') === 'form_field') {
                    $name = $p['name'] ?? '';
                    if (!is_string($name) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D', $name)
                        || in_array($name, self::RESERVED, true) || isset($fields[$name]) || count($fields) >= 50) {
                        $this->invalid();
                    }
                    foreach (['label', 'help', 'placeholder', 'options'] as $key) {
                        if (isset($p[$key]) && !is_string($p[$key])) {
                            $this->invalid();
                        }
                    }
                    if (!in_array($p['control'] ?? '', self::CONTROLS, true)
                        || (in_array($p['control'] ?? '', ['select', 'radio'], true) && self::options($p) === [])) {
                        $this->invalid();
                    }
                    if (in_array($p['control'], ['select', 'radio'], true)) {
                        foreach (self::options($p) as $option) {
                            if (mb_strlen($option) > 190) {
                                $this->invalid();
                            }
                        }
                    }
                    $fields[$name] = $p;
                }
                if (is_array($p['children'] ?? null)) {
                    $walk($p['children'], $depth + 1);
                }
            }
        };
        $walk(is_array($payload['children'] ?? null) ? $payload['children'] : []);
        if ($fields === []) {
            $this->invalid();
        }
        return $fields;
    }

    /** @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function options(array $payload): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($payload['options'] ?? '')) ?: []), static fn (string $s): bool => $s !== '')));
    }

    /** @param array<string, mixed> $payload */
    public function assertValid(array $payload): void
    {
        $fields = $this->fields($payload);
        if (!is_string($payload['action'] ?? null)) {
            $this->invalid();
        }
        $action = $this->cms->formAction($payload['action']);
        if ($action === null || !$this->validation->has($action['ruleset'])) {
            $this->invalid();
        }
        $mapping = self::mapping($payload);
        if (array_diff(array_keys($mapping), array_keys($action['fields'])) !== [] || count(array_unique(array_values($mapping))) !== count($mapping)) {
            $this->invalid();
        }
        $sources = array_map(static fn (string $name): string => $mapping[$name] ?? $name, array_keys($action['fields']));
        if (count(array_unique($sources)) !== count($sources)) {
            $this->invalid();
        }
        $missing = [];
        foreach ($action['fields'] as $name => $control) {
            $source = $mapping[$name] ?? $name;
            if (($source !== $name && isset($fields[$name])) || !isset($fields[$source]) || $fields[$source]['control'] !== $control || empty($fields[$source]['required'])) {
                $key = 'cms.form.field.' . $name;
                $label = $this->translator->trans($key);
                $missing[] = $label === $key ? $name : $label;
            }
        }
        if ($missing !== []) {
            throw new ValidationException(['form' => $this->translator->trans('cms.form.action_fields', ['%fields%' => implode(', ', $missing)])]);
        }
    }

    /**
     * Drafts and reusable partials may be incomplete; readiness is checked on submission.
     * @param array<string, mixed> $payload
     */
    public function assertEditable(array $payload): void
    {
        $count = 0;
        $walk = function (array $blocks, int $depth = 0) use (&$walk, &$count): void {
            if ($depth > 12 || count($blocks) > 100) {
                $this->invalid();
            }
            foreach ($blocks as $block) {
                if (!is_array($block) || !is_array($block['payload'] ?? null)
                    || !in_array($block['type'] ?? '', ['form_field', 'layout', 'text', 'heading', 'spacer', 'divider'], true)) {
                    $this->invalid();
                }
                if ($block['type'] === 'form_field' && ++$count > 50) {
                    $this->invalid();
                }
                if (is_array($block['payload']['children'] ?? null)) {
                    $walk($block['payload']['children'], $depth + 1);
                }
            }
        };
        $walk(is_array($payload['children'] ?? null) ? $payload['children'] : []);
    }

    /** @param list<array<string, mixed>> $blocks */
    public function assertTree(array $blocks, int $depth = 0): void
    {
        if ($depth > 16) {
            return;
        }
        foreach ($blocks as $block) {
            $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];
            if (($block['type'] ?? '') === 'form') {
                if ($depth > 12) {
                    $this->invalid();
                }
                $this->assertEditable($payload);
            }
            if (is_array($payload['children'] ?? null)) {
                $this->assertTree($payload['children'], $depth + 1);
            }
        }
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function validate(array $payload, array $input): array
    {
        $this->assertValid($payload);
        $fields = $this->fields($payload);
        $values = [];
        foreach ($fields as $name => $field) {
            if (isset($input[$name]) && !is_string($input[$name])) {
                throw new ValidationException([$name => $this->translator->trans('cms.form.invalid_value')]);
            }
            $values[$name] = trim((string) ($input[$name] ?? ''));
        }
        $this->validation->validate('cms.form.submit', ['values' => $values], ['fields' => $fields]);
        $action = $this->cms->formAction((string) $payload['action']);
        $mapping = self::mapping($payload);
        $mapped = $values;
        foreach ($mapping as $target => $source) {
            unset($mapped[$source]);
        }
        foreach ($mapping as $target => $source) {
            $mapped[$target] = $values[$source];
        }
        try {
            $this->validation->validate($action['ruleset'], $mapped);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $name => $error) {
                $errors[$mapping[$name] ?? $name] = $error;
            }
            throw new ValidationException($errors);
        }
        return $mapped;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public static function mapping(array $payload): array
    {
        $mapping = [];
        foreach (array_slice(is_array($payload['field_mapping'] ?? null) ? $payload['field_mapping'] : [], 0, 50, true) as $target => $source) {
            if (is_string($target) && is_string($source) && $source !== '') {
                $mapping[substr($target, 0, 80)] = substr($source, 0, 80);
            }
        }
        return $mapping;
    }

    /** Validate every form before production snapshots are released.
     * @param list<array<string, mixed>> $blocks
     */
    public function assertPublishable(array $blocks, int $depth = 0, ?int &$formNumber = null): void
    {
        $formNumber ??= 0;
        if ($depth > 16) {
            $this->invalid();
        }
        foreach ($blocks as $block) {
            $payload = is_array($block['payload'] ?? null) ? $block['payload'] : [];
            if (($block['type'] ?? '') === 'form') {
                ++$formNumber;
                try {
                    $this->assertValid($payload);
                } catch (ValidationException $exception) {
                    throw new ValidationException($exception->errors(), [
                        'block_id' => is_string($block['id'] ?? null) ? $block['id'] : '',
                        'form_label' => (is_string($payload['admin_label'] ?? null) && trim($payload['admin_label']) !== '')
                            ? trim($payload['admin_label'])
                            : $this->translator->trans('cms.form.publish_form_number', ['%number%' => (string) $formNumber]),
                    ]);
                }
            }
            foreach (['children', 'item_blocks'] as $key) {
                if (is_array($payload[$key] ?? null)) {
                    $this->assertPublishable($payload[$key], $depth + 1, $formNumber);
                }
            }
        }
    }

    private function invalid(): never
    {
        throw new ValidationException(['form' => $this->translator->trans('cms.form.invalid_schema')]);
    }
}
