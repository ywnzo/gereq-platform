<?php

declare(strict_types=1);

namespace Modules\SiteInquiries\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Modules\SiteInquiries\Domain\Inquiry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class InquiryCountWidget extends TwigDashboardWidget
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
        return 'site_inquiries.count';
    }
    public function title(): string
    {
        return $this->translator->trans('site_inquiries.widget.count.title');
    }
    public function type(): string
    {
        return 'metric';
    }
    public function permission(): ?string
    {
        return 'site_inquiries.view';
    }
    public function order(): int
    {
        return 10;
    }
    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $repo = $this->em->getRepository(Inquiry::class);
        return [
            'label' => $this->title(),
            'value' => (string) $repo->count(['status' => Inquiry::STATUS_OPEN]),
            'hint' => $this->translator->trans('site_inquiries.widget.count.hint', ['%count%' => (string) $repo->count([])]),
            'href' => $this->router->url('admin.site_inquiries.index'),
        ];
    }
}
