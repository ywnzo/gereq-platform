<?php

declare(strict_types=1);

namespace Gereq\Admin\Http\Developer;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Api\Auth\ApiTokenService;
use Gereq\Api\Entity\ApiToken;
use Gereq\Api\ApiRegistry;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ApiTokenController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly ApiTokenService $tokens,
        private readonly ApiRegistry $api,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = $this->em->getRepository(ApiToken::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->page('admin/developer/tokens/index.html.twig', [
            'pageTitle' => $this->translator->trans('admin.tokens.title'),
            'items' => $items,
        ]);
    }

    public function create(): Response
    {
        return $this->page('admin/developer/tokens/form.html.twig', [
            'pageTitle' => $this->translator->trans('admin.tokens.new_title'),
            'scopes' => $this->api->scopes(),
            'plain' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            $this->abort(401);
        }

        $name = trim((string) $request->request->get('name', ''));
        $scopes = $request->request->all('scopes');
        if (!is_array($scopes)) {
            $scopes = [];
        }
        $scopes = array_values(array_filter(array_map('strval', $scopes)));
        if ($request->request->get('full_access')) {
            $scopes = ['*'];
        }
        $test = (bool) $request->request->get('test');
        $expires = trim((string) $request->request->get('expires_at', ''));
        $expiresAt = $expires !== '' ? new \DateTimeImmutable($expires) : null;

        if ($name === '') {
            $this->flash('error', $this->translator->trans('admin.tokens.name_required'));

            return $this->redirect('admin.developer.tokens.create');
        }

        $created = $this->tokens->create($name, $user, $scopes, $test, $expiresAt);
        $this->auditor->log('api_token.created', 'api_token', $created['token']->id(), null, [
            'name' => $name,
            'scopes' => $scopes,
        ]);

        return $this->page('admin/developer/tokens/form.html.twig', [
            'pageTitle' => $this->translator->trans('admin.tokens.created_title'),
            'scopes' => $this->api->scopes(),
            'plain' => $created['plain'],
            'item' => $created['token'],
        ]);
    }

    public function revoke(string $id): Response
    {
        $token = $this->em->find(ApiToken::class, $id);
        if (!$token instanceof ApiToken) {
            $this->abort(404);
        }
        $token->revoke();
        $this->em->flush();
        $this->auditor->log('api_token.revoked', 'api_token', $id);
        $this->flash('success', $this->translator->trans('admin.tokens.revoked_flash'));

        return $this->redirect('admin.developer.tokens.index');
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
