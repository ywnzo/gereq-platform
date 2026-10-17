<?php

declare(strict_types=1);

namespace Gereq\Currency\Application;

use Gereq\Admin\Ui\Ui;
use Gereq\Currency\Domain\Currency;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Builds the currencies admin data table (settings panel + /admin/currencies). */
final class CurrencyAdminTable
{
    public function __construct(
        private readonly Ui $ui,
        private readonly Router $router,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<Currency> $items
     */
    public function render(array $items, ?User $user, ?Request $request = null): string
    {
        $canManage = $user instanceof User && $user->can('currency.manage');

        return $this->ui->table(
            [
                $this->ui->column('code', $this->translator->trans('currency.field.code'), [
                    'cell' => 'html',
                    'render' => static fn (Currency $item): string => '<strong>' . e($item->code()) . '</strong>',
                ]),
                $this->ui->column('name', $this->translator->trans('currency.field.name')),
                $this->ui->column('symbol', $this->translator->trans('currency.field.symbol')),
                $this->ui->column('decimalPlaces', $this->translator->trans('currency.field.decimal_places'), [
                    'cell' => 'num',
                ]),
                $this->ui->column('status', $this->translator->trans('currency.field.status'), [
                    'cell' => 'html',
                    'render' => fn (Currency $item): string => $this->ui->badge(
                        $this->translator->trans($item->isActive() ? 'currency.active' : 'currency.inactive'),
                        $item->isActive() ? 'ok' : 'muted',
                    ),
                ]),
                $this->ui->column('default', $this->translator->trans('currency.field.default'), [
                    'cell' => 'html',
                    'render' => function (Currency $item) use ($canManage): string {
                        if ($item->isDefault()) {
                            return $this->ui->badge(
                                $this->translator->trans('currency.default_badge'),
                                'warn',
                            );
                        }
                        if (!$canManage) {
                            return '—';
                        }

                        return '<button class="badge badge--muted badge--action" type="submit" form="currency-default-'
                            . e($item->code())
                            . '">' . e($this->translator->trans('currency.set_default')) . '</button>';
                    },
                ]),
            ],
            $items,
            [
                'empty_title' => $this->translator->trans('currency.empty'),
                'empty_hint' => $this->translator->trans('currency.empty.hint'),
                'actions' => $canManage
                    ? fn (Currency $item): array => [[
                        'href' => $this->router->url('admin.currencies.edit', ['code' => $item->code()], $request),
                        'label' => $this->translator->trans('common.edit'),
                        'icon' => 'edit',
                    ]]
                    : null,
            ],
        );
    }
}
