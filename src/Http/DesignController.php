<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Admin\Ui\Ui;
use Gereq\Validation\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DesignController extends Controller
{
    public function __construct(
        \Twig\Environment $twig,
        Router $router,
        \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
        private readonly Ui $ui,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $list = ListQuery::fromRequest(
            $request,
            ['name', 'email', 'status_label'],
            'name',
            'asc',
            8,
        );

        $users = $this->mockUsers();
        usort($users, static function (array $a, array $b) use ($list): int {
            $left = (string) ($a[$list->sort] ?? '');
            $right = (string) ($b[$list->sort] ?? '');
            $cmp = strcasecmp($left, $right);

            return $list->dir === 'desc' ? -$cmp : $cmp;
        });

        $t = $this->translator;
        $usersTable = $this->ui->table(
            [
                $this->ui->column('name', $t->trans('common.name'), [
                    'sortable' => true,
                    'cell' => 'user',
                    'meta' => ['meta_key' => 'phone', 'initials_key' => 'initials'],
                ]),
                $this->ui->column('email', $t->trans('common.email'), ['sortable' => true]),
                $this->ui->column('roles', $t->trans('common.roles'), [
                    'render' => static function (array $row): string {
                        $html = '<div class="table__chips">';
                        foreach ($row['roles'] as $role) {
                            $tone = e((string) ($role['tone'] ?? 'muted'));
                            $label = e((string) ($role['label'] ?? ''));
                            $html .= '<span class="badge badge--' . $tone . '">' . $label . '</span>';
                        }

                        return $html . '</div>';
                    },
                ]),
                $this->ui->column('status_label', $t->trans('common.status'), [
                    'sortable' => true,
                    'cell' => 'badge',
                    'tone' => static fn (array $row): string => (string) ($row['status_tone'] ?? 'muted'),
                ]),
            ],
            $users,
            [
                'list_query' => $list,
                'total' => 48,
                'checkable' => true,
                'row_id' => static fn (array $row): string => (string) $row['id'],
                'row_search' => static fn (array $row): string => trim(
                    (string) $row['name'] . ' ' . (string) $row['email'] . ' ' . (string) ($row['phone'] ?? ''),
                ),
                'actions' => static function () use ($t): array {
                    return [
                        ['href' => '#', 'label' => $t->trans('common.view'), 'icon' => 'eye'],
                        ['href' => '#', 'label' => $t->trans('common.edit'), 'icon' => 'edit'],
                        ['href' => '#', 'label' => $t->trans('common.delete'), 'icon' => 'trash'],
                    ];
                },
                'empty_title' => $t->trans('design.tables.empty_heading'),
                'footer' => true,
                'hash' => 'tabulky',
            ],
        );

        return $this->render('design/index.html.twig', [
            'pageTitle' => $this->translator->trans('design.page_title'),
            'design_users_table' => $usersTable,
            'design_list' => $list,
            'design_users' => $users,
        ]);
    }

    public function submit(Request $request, Validator $validator): \Symfony\Component\HttpFoundation\Response
    {
        $validator->validateRequest($request, 'design.demo');
        $this->forgetForm('design.demo');
        $this->flash('success', $this->translator->trans('design.flash.validated'));

        return $this->redirectTo('/design.html#formular');
    }

    /**
     * Living catalogue of standardized ui() helpers (forms, feedback, layout, tables).
     */
    public function uiKit(\Gereq\Http\Design\UiKitCatalog $catalog): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('design/ui.html.twig', [
            'pageTitle' => $this->translator->trans('design.ui.page_title'),
            'ui_groups' => $catalog->groups(),
        ]);
    }


    private function mockUsers(): array
    {
        $pending = $this->translator->trans('design.tables.role_pending');

        return [
            [
                'id' => '1',
                'initials' => 'JN',
                'name' => 'Jiří Novák',
                'phone' => '+420 777 111 222',
                'email' => 'jiri.novak@gereq.cz',
                'roles' => [
                    ['label' => 'Admin', 'tone' => 'info'],
                    ['label' => 'Editor', 'tone' => 'muted'],
                ],
                'status' => 'active',
                'status_label' => $this->translator->trans('common.active'),
                'status_tone' => 'ok',
            ],
            [
                'id' => '2',
                'initials' => 'AK',
                'name' => 'Anna Králová',
                'phone' => '+420 602 333 444',
                'email' => 'anna.kralova@gereq.cz',
                'roles' => [['label' => 'Viewer', 'tone' => 'muted']],
                'status' => 'inactive',
                'status_label' => $this->translator->trans('common.inactive'),
                'status_tone' => 'muted',
            ],
            [
                'id' => '3',
                'initials' => 'PM',
                'name' => 'Petr Malý',
                'phone' => $this->translator->trans('design.tables.pending'),
                'email' => 'petr.maly@gereq.cz',
                'roles' => [['label' => $pending, 'tone' => 'warn']],
                'status' => 'pending',
                'status_label' => $this->translator->trans('common.pending'),
                'status_tone' => 'danger',
            ],
            [
                'id' => '4',
                'initials' => 'ES',
                'name' => 'Eva Svobodová',
                'phone' => '+420 731 555 666',
                'email' => 'eva.svobodova@gereq.cz',
                'roles' => [['label' => 'Editor', 'tone' => 'info']],
                'status' => 'active',
                'status_label' => $this->translator->trans('common.active'),
                'status_tone' => 'ok',
            ],
            [
                'id' => '5',
                'initials' => 'TD',
                'name' => 'Tomáš Dvořák',
                'phone' => '',
                'email' => 'tomas.dvorak@partner.cz',
                'roles' => [['label' => 'Admin', 'tone' => 'info']],
                'status' => 'active',
                'status_label' => $this->translator->trans('common.active'),
                'status_tone' => 'ok',
            ],
            [
                'id' => '6',
                'initials' => 'MH',
                'name' => 'Marie Horáková',
                'phone' => '+420 777 888 999',
                'email' => 'marie.horakova@gereq.cz',
                'roles' => [
                    ['label' => 'Viewer', 'tone' => 'muted'],
                    ['label' => 'Support', 'tone' => 'info'],
                ],
                'status' => 'inactive',
                'status_label' => $this->translator->trans('common.inactive'),
                'status_tone' => 'muted',
            ],
            [
                'id' => '7',
                'initials' => 'LB',
                'name' => 'Lukáš Beneš',
                'phone' => '',
                'email' => 'lukas.benes@gereq.cz',
                'roles' => [['label' => 'Editor', 'tone' => 'muted']],
                'status' => 'active',
                'status_label' => $this->translator->trans('common.active'),
                'status_tone' => 'ok',
            ],
            [
                'id' => '8',
                'initials' => 'KC',
                'name' => 'Klára Černá',
                'phone' => $this->translator->trans('design.tables.pending'),
                'email' => 'klara.cerna@gereq.cz',
                'roles' => [['label' => $pending, 'tone' => 'warn']],
                'status' => 'pending',
                'status_label' => $this->translator->trans('common.pending'),
                'status_tone' => 'danger',
            ],
        ];
    }
}
