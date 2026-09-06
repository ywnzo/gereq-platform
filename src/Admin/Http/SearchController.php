<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Gereq\Admin\Search\AdminSearchRegistry;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SearchController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly AdminSearchRegistry $search,
        private readonly Authenticator $auth,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->auth->user();
        if ($user === null) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $q = trim((string) $request->query->get('q', ''));
        $hits = $this->search->search($q, $user);
        $data = [];
        foreach ($hits as $hit) {
            $row = $hit->toArray();
            $row['group'] = $this->translator->trans($hit->group);
            $data[] = $row;
        }

        return new JsonResponse([
            'ok' => true,
            'q' => $q,
            'results' => $data,
        ]);
    }
}
