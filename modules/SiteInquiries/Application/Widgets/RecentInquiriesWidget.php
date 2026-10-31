<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\SiteInquiries\Domain\Inquiry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class RecentInquiriesWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly Router $router,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'site_inquiries.recent';
    }
    public function title(): string
    {
        return $this->translator->trans('site_inquiries.widget.recent.title');
    }
    public function type(): string
    {
        return 'panel';
    }
    public function permission(): ?string
    {
        return 'site_inquiries.view';
    }
    public function order(): int
    {
        return 20;
    }
    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        $rows = $this->em->getRepository(Inquiry::class)->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], 5);
        $items = [];
        foreach ($rows as $inquiry) {
            $items[] = [
                'title' => $inquiry->name(),
                'meta' => $inquiry->createdAt()->format('j. n. Y H:i'),
                'href' => $this->router->url('admin.site_inquiries.show', ['id' => $inquiry->id()]),
                'badge' => [
                    'label' => $this->translator->trans('site_inquiries.widget.status.' . $inquiry->status()),
                    'tone' => $inquiry->status() === Inquiry::STATUS_OPEN ? 'warn' : 'ok',
                ],
            ];
        }
        return [
            'title' => $this->title(),
            'subtitle' => $this->translator->trans('site_inquiries.widget.recent.subtitle'),
            'href' => $this->router->url('admin.site_inquiries.index'),
            'link_label' => $this->translator->trans('site_inquiries.widget.all'),
            'items' => $items,
            'empty_message' => $this->translator->trans('site_inquiries.widget.empty'),
        ];
    }
}
