<?php

declare(strict_types=1);

namespace Gereq\Debug;

final class Dumper
{
    public function __construct(private readonly Redactor $redactor)
    {
    }

    public function dump(mixed $value, int $depth = 0): string
    {
        return $this->format($value, $depth);
    }

    /**
     * JSON-safe structure for the interactive debug-bar tree.
     *
     * @param array<int, true> $seen
     */
    public function normalize(mixed $value, int $depth = 0, array &$seen = []): mixed
    {
        if ($depth > 6) {
            return '…';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return mb_strlen($value) > 2000 ? mb_substr($value, 0, 2000) . '…' : $value;
        }

        if (is_resource($value)) {
            return '#resource(' . get_resource_type($value) . ')';
        }

        if (is_array($value)) {
            $out = [];
            $i = 0;
            foreach ($value as $key => $item) {
                if ($i++ > 120) {
                    $out['…'] = '…';
                    break;
                }
                $out[$key] = $this->normalize($item, $depth + 1, $seen);
            }

            return $out;
        }

        if (!is_object($value)) {
            return get_debug_type($value);
        }

        $id = spl_object_id($value);
        if (isset($seen[$id])) {
            return '…recursion ' . $value::class . '…';
        }
        $seen[$id] = true;

        if ($value instanceof \DateTimeInterface) {
            return [
                '_class' => $value::class,
                'datetime' => $value->format('c'),
            ];
        }

        if ($value instanceof \BackedEnum) {
            return [
                '_class' => $value::class,
                'name' => $value->name,
                'value' => $value->value,
            ];
        }

        if ($value instanceof \UnitEnum) {
            return [
                '_class' => $value::class,
                'name' => $value->name,
            ];
        }

        try {
            $vars = get_object_vars($value);
        } catch (\Throwable) {
            return ['_class' => $value::class];
        }

        $out = ['_class' => $value::class];
        foreach ($vars as $key => $item) {
            $out[$key] = $this->normalize($item, $depth + 1, $seen);
        }

        if ($vars === [] && $value instanceof \Stringable) {
            $out['__toString'] = $this->normalize((string) $value, $depth + 1, $seen);
        }

        return $out;
    }

    private function format(mixed $value, int $depth): string
    {
        if ($depth > 4) {
            return $this->span('muted', '…');
        }

        if ($value === null) {
            return $this->span('null', 'null');
        }
        if (is_bool($value)) {
            return $this->span('bool', $value ? 'true' : 'false');
        }
        if (is_int($value) || is_float($value)) {
            return $this->span('num', (string) $value);
        }
        if (is_string($value)) {
            $shown = mb_strlen($value) > 500 ? mb_substr($value, 0, 500) . '…' : $value;

            return $this->span('str', '"' . $this->esc($shown) . '"');
        }
        if (is_array($value)) {
            return $this->array($value, $depth);
        }
        if (is_object($value)) {
            return $this->object($value, $depth);
        }

        return $this->span('muted', $this->esc(get_debug_type($value)));
    }

    /** @param array<mixed> $value */
    private function array(array $value, int $depth): string
    {
        if ($value === []) {
            return $this->span('muted', '[]');
        }

        $rows = '';
        $i = 0;
        foreach ($value as $key => $item) {
            if ($i++ > 40) {
                $rows .= '<div class="gdb-dump__row">' . $this->span('muted', '…') . '</div>';
                break;
            }
            $safe = is_string($key) && $this->redactor->key($key);
            $rows .= '<div class="gdb-dump__row"><span class="gdb-dump__key">'
                . $this->esc((string) $key)
                . '</span><span class="gdb-dump__val">'
                . ($safe ? $this->span('muted', '••••') : $this->format($item, $depth + 1))
                . '</span></div>';
        }

        return '<div class="gdb-dump__arr">' . $rows . '</div>';
    }

    private function object(object $value, int $depth): string
    {
        $label = $this->span('obj', $value::class);
        if ($value instanceof \DateTimeInterface) {
            return $label . ' ' . $this->span('str', $this->esc($value->format('c')));
        }
        if ($value instanceof \Stringable) {
            return $label . ' ' . $this->span('str', '"' . $this->esc((string) $value) . '"');
        }

        try {
            $vars = get_object_vars($value);
        } catch (\Throwable) {
            return $label;
        }

        return $label . $this->array($vars, $depth);
    }

    private function span(string $type, string $html): string
    {
        return '<span class="gdb-dump gdb-dump--' . $type . '">' . $html . '</span>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
