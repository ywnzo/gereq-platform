<?php

declare(strict_types=1);

namespace Gereq\Api;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiResponse
{
    /** @param array<string, mixed> $data */
    public static function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    /**
     * @param list<mixed>|array<int, mixed> $items
     * @param array<string, mixed> $meta
     */
    public static function collection(array $items, array $meta = [], int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'data' => array_values($items),
            'meta' => $meta,
        ], $status);
    }

    /** @param array<string, list<string>|string> $fields */
    public static function error(string $code, string $message, array $fields = [], int $status = 400): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return new JsonResponse(['error' => $error], $status);
    }

    public static function notFound(string $code = 'not_found', string $message = 'Resource was not found.'): JsonResponse
    {
        return self::error($code, $message, [], 404);
    }

    public static function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return self::error('unauthenticated', $message, [], 401);
    }

    public static function forbidden(string $message = 'Forbidden.'): JsonResponse
    {
        return self::error('forbidden', $message, [], 403);
    }
}
