<?php

declare(strict_types=1);

namespace Gereq\Currency\Application\Widgets;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Admin\Dashboard\TwigDashboardWidget;
use Gereq\Http\Router;
use Gereq\Currency\Application\CurrencyBuilder;
use Gereq\Currency\Application\CurrencyConverter;
use Gereq\Currency\Domain\Currency;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Current rates of active currencies against the default currency. */
final class ExchangeRatesWidget extends TwigDashboardWidget
{
    private const LIMIT = 8;

    public function __construct(
        Environment $twig,
        private readonly EntityManagerInterface $em,
        private readonly CurrencyBuilder $money,
        private readonly CurrencyConverter $converter,
        private readonly TranslatorInterface $translator,
        private readonly Router $router,
    ) {
        parent::__construct($twig);
    }

    public function id(): string
    {
        return 'currency.rates';
    }

    public function title(): string
    {
        return $this->translator->trans('currency.widget.rates.title');
    }

    public function type(): string
    {
        return DashboardWidget::TYPE_PANEL;
    }

    public function permission(): ?string
    {
        return 'currency.view';
    }

    public function order(): int
    {
        return 32;
    }

    protected function template(): string
    {
        return 'admin/dashboard/widgets/list_panel.html.twig';
    }

    protected function data(): array
    {
        $default = $this->money->defaultCode();
        $defaultSymbol = $this->money->symbol($default);

        /** @var list<Currency> $currencies */
        $currencies = $this->em->getRepository(Currency::class)->findBy(['isActive' => true], ['code' => 'ASC']);

        $bases = [];
        foreach ($currencies as $currency) {
            if ($currency->code() !== $default) {
                $bases[] = $currency->code();
            }
        }
        $this->converter->warmLatestAgainst($default, array_slice($bases, 0, self::LIMIT));

        $items = [];
        $stale = new \DateTimeImmutable('-3 days');
        foreach ($currencies as $currency) {
            if ($currency->code() === $default) {
                continue;
            }
            if (count($items) >= self::LIMIT) {
                break;
            }

            $item = [
                'title' => $currency->code(),
                'meta' => $currency->name(),
                'icon' => 'coins',
                'href' => $this->router->url('admin.currencies.edit', ['code' => $currency->code()]),
            ];

            try {
                $result = $this->converter->convert(1, $currency->code(), $default);
                $rate = (float) $result['rate'];
                $validAt = is_string($result['rate_valid_at']) ? new \DateTimeImmutable($result['rate_valid_at']) : null;

                $item['aside'] = number_format($rate, $rate >= 100 ? 2 : 3, ',', ' ') . ' ' . $defaultSymbol;
                $item['aside_hint'] = $validAt instanceof \DateTimeImmutable ? $validAt->format('j. n. Y') : '';
                if ($validAt instanceof \DateTimeImmutable && $validAt < $stale) {
                    $item['badge'] = ['label' => $this->translator->trans('currency.widget.rates.stale'), 'tone' => 'warn'];
                }
            } catch (\Throwable) {
                $item['aside'] = '—';
                $item['badge'] = ['label' => $this->translator->trans('currency.widget.rates.missing'), 'tone' => 'danger'];
            }

            $items[] = $item;
        }

        return [
            'title' => $this->translator->trans('currency.widget.rates.title'),
            'subtitle' => $this->translator->trans('currency.widget.rates.subtitle', ['%default%' => $default]),
            'href' => $this->router->url('admin.currencies.index'),
            'link_label' => $this->translator->trans('nav.currencies'),
            'items' => $items,
            'empty_message' => $this->translator->trans('currency.widget.rates.empty'),
        ];
    }
}
