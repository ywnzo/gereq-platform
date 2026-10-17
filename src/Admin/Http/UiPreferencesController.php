<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/** Persist per-account admin UI state (open nav sections, expanded module rows). */
final class UiPreferencesController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(): JsonResponse
    {
        $user = $this->auth->user();
        if (!$user instanceof User) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        return new JsonResponse([
            'ok' => true,
            'preferences' => $user->uiPreferences(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $user = $this->auth->user();
        if (!$user instanceof User) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $raw = $request->getContent();
        $decoded = [];
        if (is_string($raw) && $raw !== '') {
            try {
                $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($json)) {
                    $decoded = $json;
                }
            } catch (\JsonException) {
                return new JsonResponse(['ok' => false, 'error' => 'invalid_json'], 400);
            }
        } else {
            $decoded = $request->request->all();
        }

        /** @var array<string, mixed> $patch */
        $patch = [];
        if (isset($decoded['nav_groups']) && is_array($decoded['nav_groups'])) {
            $patch['nav_groups'] = $decoded['nav_groups'];
        }
        if (isset($decoded['nav_trees']) && is_array($decoded['nav_trees'])) {
            $patch['nav_trees'] = $decoded['nav_trees'];
        }
        if (isset($decoded['modules_expanded']) && is_array($decoded['modules_expanded'])) {
            $patch['modules_expanded'] = $decoded['modules_expanded'];
        }

        if ($patch === []) {
            return new JsonResponse(['ok' => false, 'error' => 'empty_patch'], 422);
        }

        $prefs = $user->mergeUiPreferences($patch);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'preferences' => $prefs,
        ]);
    }
}
