<?php

declare(strict_types=1);

namespace Modules\Currency\Http;

use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationRegistry;
use Modules\Currency\Application\CurrencyConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class ConvertApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly CurrencyConverter $converter,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function convert(Request $request): JsonResponse
    {
        $data = gereq()->make(ValidationRegistry::class)->validate('currency.convert', $this->body($request));

        try {
            $result = $this->converter->convert(
                $data['amount'],
                (string) $data['currency'],
                (string) $data['target_currency'],
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error('validation_error', $e->getMessage(), [], 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error('conversion_failed', $e->getMessage(), [], 422);
        }

        return ApiResponse::data($result);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }
}
