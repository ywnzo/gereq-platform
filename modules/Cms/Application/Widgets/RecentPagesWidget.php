<?php

declare(strict_types=1);

namespace Modules\Cms\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Modules\Cms\Domain\Page;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Recently updated pages. */
final class RecentPagesWidget extends TwigDashboardWidget
{
    private const LIMIT = 8;

    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'cms.recent';
    }

    public function title(): string
    {
        return $this->translator->trans('cms.widget.recent.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'cms.view';
    }

    public function order(): int
    {
        return 25;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        /** @var list<Page> $pages */
        $pages = $this->em->getRepository(Page::class)->findBy([], ['updatedAt' => 'DESC'], self::LIMIT);

        $user = $this->auth->user();
        $canEdit = $user !== null && $user->can('cms.manage');

        $items = [];
        foreach ($pages as $page) {
            $items[] = [
                'title' => $page->title(),
                'meta' => '/' . ltrim($page->slug(), '/'),
                'icon' => $page->template() === 'landing' ? 'layout' : 'file',
                'href' => $canEdit
                    ? $this->router->url('admin.cms.pages.edit', ['id' => $page->id()])
                    : $this->router->url('admin.cms.pages.index'),
                'aside' => $page->updatedAt()->format('j. n.'),
                'aside_hint' => $page->updatedAt()->format('H:i'),
                'badge' => [
                    'label' => $this->translator->trans($page->statusLabel()),
                    'tone' => match ($page->status()) {
                        'published' => 'ok',
                        'draft' => 'warn',
                        default => 'muted',
                    },
                ],
            ];
        }

        return [
            'title' => $this->translator->trans('cms.widget.recent.title'),
            'subtitle' => $this->translator->trans('cms.widget.recent.subtitle'),
            'href' => $this->router->url('admin.cms.pages.index'),
            'link_label' => $this->translator->trans('nav.cms'),
            'items' => $items,
            'empty_message' => $this->translator->trans('cms.widget.recent.empty'),
        ];
    }
}
