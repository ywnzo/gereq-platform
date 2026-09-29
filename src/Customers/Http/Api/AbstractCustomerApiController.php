<?php

declare(strict_types=1);

namespace Gereq\Customers\Http\Api;

use Gereq\Http\Controller;
use Gereq\Validation\ValidationRegistry;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractCustomerApiController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    protected function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $merged */
                $merged = array_merge($data, $decoded);

                return $merged;
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function validateBody(Request $request, string $ruleset, array $context = []): array
    {
        return gereq()->make(ValidationRegistry::class)->validate($ruleset, $this->body($request), $context);
    }

    protected function boolValue(mixed $value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }
}
