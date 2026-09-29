<?php

declare(strict_types=1);

namespace Gereq\Currency\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\Currency\Application\CurrencyBuilder;
use Gereq\Currency\Domain\Currency;
use Gereq\Currency\Domain\ExchangeRate;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Active currencies plus age of the latest exchange rate. */
final class CurrencyStatsWidget extends TwigDashboardWidget
{
    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly CurrencyBuilder $money,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'currency.stats';
    }

    public function title(): string
    {
        return $this->translator->trans('currency.widget.stats.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_METRIC;
    }

    public function permission(): ?string
    {
        return 'currency.view';
    }

    public function order(): int
    {
        return 58;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/metric.html.twig';
    }

    protected function data(): array
    {
        $active = (int) $this->em->getRepository(Currency::class)->createQueryBuilder('c')
            ->select('COUNT(c.code)')
            ->where('c.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        $latest = $this->em->getRepository(ExchangeRate::class)->findOneBy([], ['validAt' => 'DESC']);
        $hint = $latest instanceof ExchangeRate
            ? $this->translator->trans('currency.widget.stats.rates_at', [
                '%date%' => $latest->validAt()->format('j. n. Y'),
                '%default%' => $this->money->defaultCode(),
            ])
            : $this->translator->trans('currency.widget.stats.no_rates', [
                '%default%' => $this->money->defaultCode(),
            ]);

        return [
            'label' => $this->translator->trans('currency.widget.stats.title'),
            'value' => (string) $active,
            'hint' => $hint,
            'href' => $this->router->url('admin.currencies.index'),
        ];
    }
}
