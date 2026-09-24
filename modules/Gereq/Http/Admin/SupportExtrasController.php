<?php

declare(strict_types=1);

namespace Modules\Gereq\Http\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Gereq\Domain\Automation;
use Modules\Gereq\Domain\CannedReply;
use Modules\Gereq\Domain\KbArticle;
use Modules\Gereq\Domain\SlaPolicy;
use Modules\Gereq\Domain\SupportTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Phase 4 secondary SupportFlow resources (CRUD stubs). */
final class SupportExtrasController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        return $this->page('@gereq/ticketing/extras.html.twig', [
            'pageTitle' => $this->translator->trans('gereq.ticketing.extras'),
            'sla' => $this->em->getRepository(SlaPolicy::class)->findBy([], ['name' => 'ASC'], 50),
            'canned' => $this->em->getRepository(CannedReply::class)->findBy([], ['name' => 'ASC'], 50),
            'tags' => $this->em->getRepository(SupportTag::class)->findBy([], ['name' => 'ASC'], 50),
            'kb' => $this->em->getRepository(KbArticle::class)->findBy([], ['name' => 'ASC'], 50),
            'automations' => $this->em->getRepository(Automation::class)->findBy([], ['name' => 'ASC'], 50),
        ]);
    }

    public function store(Request $request): Response
    {
        $type = (string) $request->request->get('type', '');
        $name = trim((string) $request->request->get('name', ''));
        $body = trim((string) $request->request->get('body', '')) ?: null;
        if ($name === '') {
            $this->flash('error', $this->translator->trans('gereq.ticketing.extras_name_required'));

            return $this->redirect('admin.gereq.ticketing.extras');
        }
        $entity = match ($type) {
            'sla' => new SlaPolicy($name, $body),
            'canned' => new CannedReply($name, $body),
            'tag' => new SupportTag($name, $body),
            'kb' => new KbArticle($name, $body),
            'automation' => new Automation($name, $body),
            default => null,
        };
        if ($entity === null) {
            $this->flash('error', $this->translator->trans('gereq.ticketing.extras_invalid'));

            return $this->redirect('admin.gereq.ticketing.extras');
        }
        $this->em->persist($entity);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('gereq.ticketing.extras_saved'));

        return $this->redirect('admin.gereq.ticketing.extras');
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
