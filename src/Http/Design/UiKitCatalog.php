<?php

declare(strict_types=1);

namespace Gereq\Http\Design;

use Gereq\Admin\Ui\Ui;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Builds living ui() helper demos for the debug-only design catalogue. */
final class UiKitCatalog
{
    public function __construct(
        private readonly Ui $ui,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, lead: string, demos: list<array{id: string, title: string, signature: string, code: string, html: string}>}>
     */
    public function groups(): array
    {
        $ui = $this->ui;
        $t = $this->translator;

        $formFields = $ui->input([
            'name' => 'demo_name',
            'label' => $t->trans('common.name'),
            'value' => 'Jiří Novák',
            'hint' => 'ui()->input([...])',
            'form' => null,
        ])
            . $ui->input([
                'name' => 'demo_email',
                'type' => 'email',
                'label' => $t->trans('common.email'),
                'value' => 'jiri@gereq.cz',
                'form' => null,
            ])
            . $ui->input([
                'name' => 'demo_search',
                'type' => 'search',
                'label' => $t->trans('common.search'),
                'placeholder' => '…',
                'form' => null,
            ]);

        $textareaMini = $ui->textarea([
            'name' => 'demo_note',
            'label' => $t->trans('design.ui.demo_note'),
            'value' => 'Krátká poznámka',
            'size' => 'mini',
            'rows' => 3,
            'form' => null,
        ]);

        $select = $ui->select([
            'name' => 'demo_status',
            'label' => $t->trans('common.status'),
            'value' => 'active',
            'options' => [
                ['value' => 'active', 'label' => $t->trans('common.active')],
                ['value' => 'inactive', 'label' => $t->trans('common.inactive')],
                ['value' => 'pending', 'label' => $t->trans('common.pending')],
            ],
            'form' => null,
        ]);

        $checks = $ui->checkbox([
            'name' => 'demo_agree',
            'label' => $t->trans('design.ui.demo_checkbox'),
            'checked' => true,
            'form' => null,
        ])
            . $ui->switch([
                'name' => 'demo_on',
                'label' => $t->trans('design.ui.demo_switch'),
                'on' => true,
                'form' => null,
            ]);

        $radios = $ui->radio([
            'name' => 'demo_plan',
            'label' => $t->trans('design.ui.demo_radio'),
            'value' => 'pro',
            'options' => [
                ['value' => 'basic', 'label' => 'Basic'],
                ['value' => 'pro', 'label' => 'Pro'],
                ['value' => 'ent', 'label' => 'Enterprise'],
            ],
            'form' => null,
        ]);

        $file = $ui->file([
            'name' => 'demo_file',
            'label' => $t->trans('design.ui.demo_file'),
            'compact' => true,
            'form' => null,
        ]);

        $badges = '<div class="grq-cluster">'
            . $ui->badge('muted', 'muted')
            . $ui->badge('ok', 'ok')
            . $ui->badge('warn', 'warn')
            . $ui->badge('danger', 'danger')
            . $ui->badge('info', 'info')
            . $ui->badge('signal', 'signal')
            . '</div>';

        $empty = $ui->emptyState(
            $t->trans('design.tables.empty_heading'),
            $t->trans('design.tables.empty_hint'),
            '<button type="button" class="btn btn--primary btn--sm">' . e($t->trans('design.tables.new')) . '</button>',
        );

        $header = $ui->pageHeader($t->trans('design.ui.demo_header'), [
            'meta' => $t->trans('design.ui.demo_header_meta'),
            'actions' => '<button type="button" class="btn btn--primary btn--sm">' . e($t->trans('common.save')) . '</button>',
        ]);

        $grid = $ui->grid(
            2,
            $ui->cell('<p class="grq-text-ui">cell 1</p><p class="grq-text-meta muted">ui()-&gt;cell()</p>')
            . $ui->cell('<p class="grq-text-ui">cell 2</p><p class="grq-text-meta muted">span default</p>'),
            ['compact' => true],
        );

        $drawer = $ui->drawerTrigger('ui-kit-drawer', $t->trans('design.ui.demo_drawer_open'), 'soft', 'panel')
            . $ui->drawer(
                'ui-kit-drawer',
                '<p class="grq-text-body">' . e($t->trans('design.ui.demo_drawer_body')) . '</p>',
                $t->trans('design.ui.demo_drawer_title'),
                ['host' => true],
            );

        $table = $ui->table(
            [
                $ui->column('name', $t->trans('common.name'), ['sortable' => true, 'cell' => 'user', 'meta' => ['meta_key' => 'meta', 'initials_key' => 'initials']]),
                $ui->column('email', $t->trans('common.email')),
                $ui->column('status', $t->trans('common.status'), [
                    'cell' => 'badge',
                    'tone' => static fn (array $row): string => (string) ($row['tone'] ?? 'muted'),
                ]),
                $ui->column('qty', $t->trans('design.tables.col_items'), ['cell' => 'num']),
            ],
            [
                ['name' => 'Ada Lovelace', 'initials' => 'AL', 'meta' => 'Math', 'email' => 'ada@gereq.cz', 'status' => $t->trans('common.active'), 'tone' => 'ok', 'qty' => '12'],
                ['name' => 'Alan Turing', 'initials' => 'AT', 'meta' => '', 'email' => 'alan@gereq.cz', 'status' => $t->trans('common.inactive'), 'tone' => 'muted', 'qty' => '3'],
                ['name' => 'Grace Hopper', 'initials' => 'GH', 'meta' => 'Navy', 'email' => 'grace@gereq.cz', 'status' => $t->trans('common.pending'), 'tone' => 'danger', 'qty' => '7'],
            ],
            [
                'sort' => 'name',
                'dir' => 'asc',
                'checkable' => true,
                'row_id' => static fn (array $row): string => (string) $row['email'],
                'actions' => static fn (): array => [
                    ['href' => '#', 'label' => $t->trans('common.view'), 'icon' => 'eye'],
                    ['href' => '#', 'label' => $t->trans('common.edit'), 'icon' => 'edit'],
                    ['href' => '#', 'label' => $t->trans('common.delete'), 'icon' => 'trash'],
                ],
                'footer' => true,
                'total' => 3,
                'page' => 1,
                'pages' => 1,
                'per_page' => 3,
                'hash' => 'table',
            ],
        );

        $icons = '<div class="grq-cluster">'
            . $ui->icon('eye') . $ui->icon('edit') . $ui->icon('trash') . $ui->icon('plus') . $ui->icon('settings')
            . $ui->icon('download') . $ui->icon('search') . $ui->icon('check')
            . '</div>';

        $inputExtras = $ui->input([
            'name' => 'demo_password',
            'type' => 'password',
            'label' => $t->trans('design.ui.demo_password'),
            'value' => 'secret',
            'form' => null,
        ])
            . $ui->input([
                'name' => 'demo_price',
                'type' => 'number',
                'label' => $t->trans('design.ui.demo_price'),
                'value' => '1290',
                'prefix' => 'Kč',
                'step' => '1',
                'form' => null,
            ])
            . $ui->input([
                'name' => 'demo_clear',
                'label' => $t->trans('design.ui.demo_clearable'),
                'value' => 'clearable',
                'clearable' => true,
                'form' => null,
            ]);

        $selectSearch = $ui->select([
            'name' => 'demo_city',
            'label' => $t->trans('design.ui.demo_searchable'),
            'value' => 'prg',
            'searchable' => true,
            'options' => [
                ['value' => 'prg', 'label' => 'Praha'],
                ['value' => 'brn', 'label' => 'Brno'],
                ['value' => 'ova', 'label' => 'Ostrava'],
                ['value' => 'plz', 'label' => 'Plzeň'],
                ['value' => 'ol', 'label' => 'Olomouc'],
            ],
            'form' => null,
        ]);

        $editor = $ui->editor([
            'name' => 'demo_body',
            'label' => $t->trans('design.ui.demo_editor'),
            'value' => '<p><strong>GEREQ</strong> rich text — <em>TipTap</em>.</p>',
            'hint' => 'ui()->editor([...])',
            'form' => null,
        ]);

        $container = $ui->container('grq-box grq-box--soft', '<p class="grq-text-ui">ui()-&gt;container(\'grq-box …\', $html)</p><p class="grq-text-meta muted">' . e($t->trans('design.ui.demo_container_hint')) . '</p>');

        $breadcrumbs = '<nav class="breadcrumbs" aria-label="Breadcrumb">'
            . $ui->partial('ui/breadcrumbs', [
                'items' => [
                    ['label' => 'Admin', 'url' => '#'],
                    ['label' => $t->trans('design.ui.group_partials'), 'url' => '#'],
                    ['label' => 'ui()->partial()'],
                ],
            ])
            . '</nav>';

        $thumbs = '<div class="grq-cluster">'
            . $ui->partial('ui/table_thumb', ['placeholder' => true, 'width' => 48, 'height' => 48])
            . $ui->partial('ui/table_thumb', ['placeholder' => true, 'width' => 40, 'height' => 40])
            . '</div>';

        $rowActions = '<table class="table"><tbody><tr>'
            . '<td class="table__actions"><div class="table__action-btns">'
            . $ui->partial('ui/row_action', ['href' => '#', 'label' => $t->trans('common.view'), 'icon' => 'eye'])
            . $ui->partial('ui/row_action', ['href' => '#', 'label' => $t->trans('common.edit'), 'icon' => 'edit'])
            . $ui->partial('ui/row_action', ['href' => '#', 'label' => $t->trans('common.delete'), 'icon' => 'trash'])
            . '</div></td></tr></tbody></table>';

        $sortTh = '<table class="table"><thead><tr>'
            . $ui->sortTh([
                'label' => $t->trans('common.name'),
                'column' => 'name',
                'sort' => 'name',
                'dir' => 'asc',
                'query' => [],
                'hash' => 'sort-th',
            ])
            . $ui->sortTh([
                'label' => $t->trans('common.email'),
                'column' => 'email',
                'sort' => 'name',
                'dir' => 'asc',
                'query' => [],
                'hash' => 'sort-th',
            ])
            . '</tr></thead></table>';

        $footer = $ui->tableFooter([
            'total' => 48,
            'page' => 2,
            'pages' => 6,
            'perPage' => 8,
            'search' => '',
            'sort' => 'name',
            'dir' => 'asc',
            'hash' => 'footer',
        ]);

        $cellsTable = $ui->table(
            [
                $ui->column('sku', 'SKU', ['cell' => 'mute']),
                $ui->column('title', $t->trans('design.tables.col_product'), [
                    'render' => static function (array $row) use ($ui): string {
                        return '<div class="table__title table__title--with-icon">'
                            . $ui->partial('ui/table_thumb', ['placeholder' => true, 'width' => 40, 'height' => 40])
                            . '<div class="table__title-text"><a href="#">' . e((string) $row['title']) . '</a>'
                            . '<span class="table__meta">' . e((string) $row['meta']) . '</span></div></div>';
                    },
                ]),
                $ui->column('price', $t->trans('design.tables.col_price'), ['cell' => 'num']),
                $ui->column('note', $t->trans('design.tables.col_note'), ['cell' => 'html']),
            ],
            [
                ['sku' => 'GRQ-1001', 'title' => 'Signal Hoodie', 'meta' => 'Merch', 'price' => '1 290 Kč', 'note' => '<span class="muted">html cell</span>'],
                ['sku' => 'GRQ-1002', 'title' => 'Ink Cap', 'meta' => 'One size', 'price' => '490 Kč', 'note' => '<code>mute / num / html</code>'],
            ],
            ['footer' => false],
        );

        $emptyTable = $ui->table(
            [
                $ui->column('name', $t->trans('common.name')),
                $ui->column('email', $t->trans('common.email')),
            ],
            [],
            [
                'empty_title' => $t->trans('design.tables.empty_heading'),
                'empty_hint' => $t->trans('design.tables.empty_hint'),
            ],
        );

        $media = $ui->partial('ui/media_box', [
            'name' => 'demo_image',
            'label' => $t->trans('design.ui.demo_media'),
            'value' => '',
            'optional' => true,
            'has_media' => true,
            'library' => [
                ['value' => '1', 'label' => 'Hero.jpg', 'thumb' => null, 'mime' => 'image/jpeg', 'ext' => 'jpg'],
                ['value' => '2', 'label' => 'Logo.png', 'thumb' => null, 'mime' => 'image/png', 'ext' => 'png'],
            ],
            'form' => null,
        ]);

        $statusChips = '<div class="grq-cluster">'
            . '<span class="status-chip status-chip--tone-success"><span class="status-chip__dot"></span>' . e($t->trans('design.tables.stock_ok')) . '</span>'
            . '<span class="status-chip status-chip--tone-warn"><span class="status-chip__dot"></span>' . e($t->trans('design.tables.stock_low')) . '</span>'
            . '<span class="status-chip status-chip--tone-danger"><span class="status-chip__dot"></span>' . e($t->trans('design.tables.stock_out')) . '</span>'
            . '<span class="status-chip status-chip--tone-info"><span class="status-chip__dot"></span>Info</span>'
            . '<span class="status-chip status-chip--tone-indigo"><span class="status-chip__dot"></span>Indigo</span>'
            . '</div>';

        $confirm = '<div class="grq-cluster">'
            . '<button type="button" class="btn btn--soft btn--sm" data-confirm="' . e($t->trans('design.ui.demo_confirm_msg')) . '">'
            . e($t->trans('design.ui.demo_confirm_btn')) . '</button>'
            . '<button type="button" class="btn btn--danger btn--sm" id="ui-kit-confirm-js">'
            . e($t->trans('design.ui.demo_confirm_js')) . '</button>'
            . '</div>'
            . '<script>'
            . 'document.getElementById("ui-kit-confirm-js")?.addEventListener("click",()=>{'
            . 'window.gereq?.confirm?.({title:' . json_encode($t->trans('design.ui.demo_confirm_title'), JSON_UNESCAPED_UNICODE)
            . ',message:' . json_encode($t->trans('design.ui.demo_confirm_msg'), JSON_UNESCAPED_UNICODE) . '});'
            . '});'
            . '</script>';


        $buttons = '<div class="grq-stack grq-stack--md">'
            . '<div class="grq-cluster">'
            . '<button type="button" class="btn btn--primary">primary</button>'
            . '<button type="button" class="btn btn--soft">soft</button>'
            . '<button type="button" class="btn btn--outline">outline</button>'
            . '<button type="button" class="btn btn--ghost">ghost</button>'
            . '<button type="button" class="btn btn--link">link</button>'
            . '<button type="button" class="btn btn--dashed">dashed</button>'
            . '</div>'
            . '<div class="grq-cluster">'
            . '<button type="button" class="btn btn--success">success</button>'
            . '<button type="button" class="btn btn--warn">warn</button>'
            . '<button type="button" class="btn btn--danger">danger</button>'
            . '<button type="button" class="btn btn--danger-outline">danger-outline</button>'
            . '<button type="button" class="btn btn--ink">ink</button>'
            . '<button type="button" class="btn btn--sky">sky</button>'
            . '<button type="button" class="btn btn--violet">violet</button>'
            . '</div>'
            . '<div class="grq-cluster">'
            . '<button type="button" class="btn btn--primary btn--xs">xs</button>'
            . '<button type="button" class="btn btn--primary btn--sm">sm</button>'
            . '<button type="button" class="btn btn--primary">md</button>'
            . '<button type="button" class="btn btn--primary btn--lg">lg</button>'
            . '<button type="button" class="btn btn--primary" disabled>disabled</button>'
            . '</div></div>';

        $alerts = '<div class="grq-stack grq-stack--sm">'
            . '<div class="alert alert--success" role="status">' . e($t->trans('design.ui.demo_alert_ok')) . '</div>'
            . '<div class="alert alert--warn" role="status">' . e($t->trans('design.ui.demo_alert_warn')) . '</div>'
            . '<div class="alert alert--danger" role="alert">' . e($t->trans('design.ui.demo_alert_err')) . '</div>'
            . '</div>';

        $toasts = '<div class="grq-cluster">'
            . '<button type="button" class="btn btn--soft btn--sm" data-ui-toast="success">' . e($t->trans('design.ui.demo_toast_ok')) . '</button>'
            . '<button type="button" class="btn btn--soft btn--sm" data-ui-toast="info">' . e($t->trans('design.ui.demo_toast_info')) . '</button>'
            . '<button type="button" class="btn btn--danger btn--sm" data-ui-toast="error">' . e($t->trans('design.ui.demo_toast_err')) . '</button>'
            . '</div>';

        $tableToggle = '<div class="grq-cluster">'
            . $ui->partial('ui/table_toggle', [
                'action' => '#',
                'on' => true,
                'title_on' => $t->trans('ui.table.toggle.on'),
                'title_off' => $t->trans('ui.table.toggle.off'),
            ])
            . $ui->partial('ui/table_toggle', [
                'action' => '#',
                'on' => false,
            ])
            . '<span class="grq-text-meta muted">ui/table_toggle</span>'
            . '</div>';

        $inputStates = $ui->input([
            'name' => 'demo_ro',
            'label' => $t->trans('design.ui.demo_readonly'),
            'value' => 'readonly',
            'readonly' => true,
            'form' => null,
        ])
            . $ui->input([
                'name' => 'demo_dis',
                'label' => $t->trans('design.ui.demo_disabled'),
                'value' => 'disabled',
                'disabled' => true,
                'form' => null,
            ])
            . $ui->input([
                'name' => 'demo_color',
                'type' => 'color',
                'label' => $t->trans('design.ui.demo_color'),
                'value' => '#625bff',
                'form' => null,
            ]);

        $gridSpan = $ui->grid(
            3,
            $ui->cell('<p class="grq-text-ui">span 1</p>', 1)
            . $ui->cell('<p class="grq-text-ui">span 2</p>', 2)
            . $ui->cell('<p class="grq-text-ui">span full</p><p class="grq-text-meta muted">ui()-&gt;cell($html, \'full\')</p>', 'full'),
            ['compact' => true],
        );

        $panel = '<section class="panel"><header class="panel__head"><h2>' . e($t->trans('design.ui.demo_panel')) . '</h2></header>'
            . '<div class="panel__body"><p class="grq-text-meta">' . e($t->trans('design.ui.demo_panel_hint')) . '</p></div></section>';

        $bulk = '<div class="bulk-bar" style="display:flex">'
            . '<div class="bulk-bar__meta"><strong>3</strong> <span>' . e($t->trans('design.tables.bulk_selected')) . '</span></div>'
            . '<div class="bulk-bar__actions">'
            . '<button type="button" class="btn btn--soft btn--sm">' . e($t->trans('design.tables.bulk_export')) . '</button>'
            . '<button type="button" class="btn btn--danger btn--sm">' . e($t->trans('design.tables.bulk_disable')) . '</button>'
            . '</div></div>';

        $errorSummary = '<div class="g-summary" role="alert">'
            . '<p class="g-summary__kicker">' . e($t->trans('design.ui.demo_errors_kicker')) . '</p>'
            . '<p class="g-summary__title">' . e($t->trans('design.ui.demo_errors_title')) . '</p>'
            . '<ul class="g-summary__list"><li><a href="#f-demo_email">E-mail is required</a></li>'
            . '<li><a href="#f-demo_name">Name is too short</a></li></ul></div>';

        $macros = '<div class="grq-stack grq-stack--sm">'
            . '<p class="grq-text-meta">' . e($t->trans('design.ui.demo_macros_hint')) . '</p>'
            . '<pre class="ui-kit__inline-pre"><code>'
            . '{% import \'ui/macros.html.twig\' as ui %}' . "\n"
            . '{{ ui.badge(\'TOP\', \'signal\') }}' . "\n"
            . '{{ ui.empty(\'Nothing yet\', \'Hint\') }}'
            . '</code></pre>'
            . '<div class="grq-cluster">' . $ui->badge('TOP', 'signal') . $ui->badge('macro', 'info') . '</div>'
            . '</div>';

        $formsApi = '<div class="grq-stack grq-stack--sm">'
            . '<p class="grq-text-meta">' . e($t->trans('design.ui.demo_forms_hint')) . '</p>'
            . '<pre class="ui-kit__inline-pre"><code>'
            . 'forms()->render($entity);' . "\n"
            . 'forms()->render($entity, [\'group\' => \'basic\']);' . "\n"
            . 'forms()->requiredFields(\'customers.save\');'
            . '</code></pre></div>';

        return [
            [
                'id' => 'forms',
                'title' => $t->trans('design.ui.group_forms'),
                'lead' => $t->trans('design.ui.group_forms_lead'),
                'demos' => [
                    $this->uiDemo(
                        'input',
                        'ui()->input()',
                        'ui()->input(array $data): string',
                        <<<'PHP'
ui()->input([
    'name' => 'email',
    'type' => 'email', // text|email|search|password|number|color|…
    'label' => __('common.email'),
    'value' => 'jiri@gereq.cz',
    'hint' => 'Optional hint',
    'form' => null, // or form id for old()/errors
]);
PHP,
                        '<form class="grq-stack grq-stack--md" onsubmit="return false">' . $formFields . '</form>',
                    ),
                    $this->uiDemo(
                        'input-extras',
                        'ui()->input() — password / addon / clearable',
                        'ui()->input(array $data): string',
                        <<<'PHP'
ui()->input(['name' => 'password', 'type' => 'password', 'label' => 'Password', 'form' => null]);
ui()->input(['name' => 'price', 'type' => 'number', 'prefix' => 'Kč', 'form' => null]);
ui()->input(['name' => 'q', 'clearable' => true, 'form' => null]);
PHP,
                        '<form class="grq-stack grq-stack--md" onsubmit="return false">' . $inputExtras . '</form>',
                    ),
                    $this->uiDemo(
                        'textarea',
                        'ui()->textarea()',
                        'ui()->textarea(array $data): string',
                        <<<'PHP'
// Plain mini textarea (size=mini). Default size renders the rich editor.
ui()->textarea([
    'name' => 'note',
    'label' => 'Note',
    'value' => '…',
    'size' => 'mini',
    'rows' => 3,
    'form' => null,
]);
PHP,
                        '<form class="grq-stack" onsubmit="return false">' . $textareaMini . '</form>',
                    ),
                    $this->uiDemo(
                        'editor',
                        'ui()->editor()',
                        'ui()->editor(array $data): string',
                        <<<'PHP'
ui()->editor([
    'name' => 'body',
    'label' => 'Content',
    'value' => '<p>Hello</p>',
    'form' => null,
]);
PHP,
                        '<form class="grq-stack" onsubmit="return false">' . $editor . '</form>',
                    ),
                    $this->uiDemo(
                        'select',
                        'ui()->select()',
                        'ui()->select(array $data): string',
                        <<<'PHP'
ui()->select([
    'name' => 'status',
    'label' => __('common.status'),
    'value' => 'active',
    'options' => [
        ['value' => 'active', 'label' => __('common.active')],
        ['value' => 'inactive', 'label' => __('common.inactive')],
    ],
    'searchable' => false,
    'form' => null,
]);
PHP,
                        '<form class="grq-stack" onsubmit="return false" style="max-width:22rem">' . $select . '</form>',
                    ),
                    $this->uiDemo(
                        'select-search',
                        'ui()->select() — searchable',
                        'ui()->select(array $data): string',
                        <<<'PHP'
ui()->select([
    'name' => 'city',
    'label' => 'City',
    'searchable' => true,
    'options' => [/* … */],
    'form' => null,
]);
PHP,
                        '<form class="grq-stack" onsubmit="return false" style="max-width:22rem">' . $selectSearch . '</form>',
                    ),
                    $this->uiDemo(
                        'check-switch',
                        'ui()->checkbox() / ui()->switch()',
                        'ui()->checkbox(array $data): string · ui()->switch(array $data): string',
                        <<<'PHP'
ui()->checkbox(['name' => 'agree', 'label' => 'I agree', 'checked' => true, 'form' => null]);
ui()->switch(['name' => 'enabled', 'label' => 'Enabled', 'on' => true, 'form' => null]);
PHP,
                        '<form class="grq-stack grq-stack--md" onsubmit="return false">' . $checks . '</form>',
                    ),
                    $this->uiDemo(
                        'radio',
                        'ui()->radio()',
                        'ui()->radio(array $data): string',
                        <<<'PHP'
ui()->radio([
    'name' => 'plan',
    'label' => 'Plan',
    'value' => 'pro',
    'options' => [
        ['value' => 'basic', 'label' => 'Basic'],
        ['value' => 'pro', 'label' => 'Pro'],
    ],
    'form' => null,
]);
PHP,
                        '<form class="grq-stack" onsubmit="return false">' . $radios . '</form>',
                    ),
                    $this->uiDemo(
                        'file',
                        'ui()->file()',
                        'ui()->file(array $data): string',
                        <<<'PHP'
ui()->file([
    'name' => 'attachment',
    'label' => 'Attachment',
    'compact' => true,
    'form' => null,
]);
PHP,
                        '<form class="grq-stack" onsubmit="return false">' . $file . '</form>',
                    ),
                    $this->uiDemo(
                        'media-box',
                        'ui()->partial(\'ui/media_box\')',
                        'ui()->partial(string $template, array $data = []): string',
                        <<<'PHP'
ui()->partial('ui/media_box', [
    'name' => 'image_url',
    'label' => 'Image',
    'has_media' => true,
    'library' => [/* assets */],
    'form' => null,
]);
PHP,
                        '<form class="grq-stack" onsubmit="return false">' . $media . '</form>',
                    ),
                ],
            ],
            [
                'id' => 'feedback',
                'title' => $t->trans('design.ui.group_feedback'),
                'lead' => $t->trans('design.ui.group_feedback_lead'),
                'demos' => [
                    $this->uiDemo(
                        'badge',
                        'ui()->badge()',
                        "ui()->badge(string \$label, string \$tone = 'muted', ?string \$class = null): string",
                        <<<'PHP'
ui()->badge('TOP', 'signal');
ui()->badge('Active', 'ok');
// tones: muted|ok|warn|danger|info|signal|…
PHP,
                        $badges,
                    ),
                    $this->uiDemo(
                        'status-chips',
                        'status-chip (CSS pattern)',
                        'HTML · .status-chip.status-chip--tone-*',
                        <<<'HTML'
<span class="status-chip status-chip--tone-success">
  <span class="status-chip__dot"></span> In stock
</span>
HTML,
                        $statusChips,
                    ),
                    $this->uiDemo(
                        'empty',
                        'ui()->emptyState()',
                        'ui()->emptyState(string $title, ?string $hint = null, ?string $actionHtml = null): string',
                        <<<'PHP'
ui()->emptyState(
    __('empty.generic'),
    'Optional hint',
    '<a class="btn btn--primary btn--sm" href="…">New</a>',
);
PHP,
                        $empty,
                    ),
                    $this->uiDemo(
                        'icon',
                        'ui()->icon()',
                        'ui()->icon(string $name, string $class = \'\'): string',
                        <<<'PHP'
ui()->icon('eye');
ui()->icon('edit', 'g-icon--sm');
PHP,
                        $icons,
                    ),
                    $this->uiDemo(
                        'confirm',
                        'window.gereq.confirm / data-confirm',
                        'Never use window.alert / confirm / prompt',
                        <<<'JS'
// Preferred
await window.gereq.confirm({ title: 'Delete?', message: '…' });

// Forms
<button data-confirm="Really?">Delete</button>
JS,
                        $confirm,
                    ),
                ],
            ],
            [
                'id' => 'layout',
                'title' => $t->trans('design.ui.group_layout'),
                'lead' => $t->trans('design.ui.group_layout_lead'),
                'demos' => [
                    $this->uiDemo(
                        'page-header',
                        'ui()->pageHeader()',
                        'ui()->pageHeader(string $title, array $options = []): string',
                        <<<'PHP'
ui()->pageHeader('Users', [
    'meta' => '12 records',
    'actions' => '<button class="btn btn--primary">New</button>',
]);
PHP,
                        $header,
                    ),
                    $this->uiDemo(
                        'grid',
                        'ui()->grid() / ui()->cell()',
                        'ui()->grid(int $cols, string $body, array $options = []): string',
                        <<<'PHP'
ui()->grid(2,
    ui()->cell('Left') . ui()->cell('Right'),
    ['compact' => true],
);
PHP,
                        $grid,
                    ),
                    $this->uiDemo(
                        'container',
                        'ui()->container()',
                        'ui()->container(int|string $colsOrClass, string $body = \'\', array $options = []): string',
                        <<<'PHP'
// int 1–4 → same as grid(); string → wrapper class
ui()->container('grq-box', '<p>…</p>');
ui()->container(2, ui()->cell('A') . ui()->cell('B'));
PHP,
                        $container,
                    ),
                    $this->uiDemo(
                        'drawer',
                        'ui()->drawer() / ui()->drawerTrigger()',
                        'ui()->drawer(string $id, string $body, ?string $title = null, array $options = []): string',
                        <<<'PHP'
ui()->drawerTrigger('demo', 'Open', 'soft', 'panel');
ui()->drawer('demo', '<p>Body</p>', 'Title', ['host' => true]);
PHP,
                        $drawer,
                    ),
                    $this->uiDemo(
                        'breadcrumbs',
                        'ui()->partial(\'ui/breadcrumbs\')',
                        'ui()->partial(\'ui/breadcrumbs\', [\'items\' => […]]): string',
                        <<<'PHP'
ui()->partial('ui/breadcrumbs', [
    'items' => [
        ['label' => 'Admin', 'url' => \Gereq\Support\AdminPath::base()],
        ['label' => 'Users'],
    ],
]);
PHP,
                        $breadcrumbs,
                    ),
                ],
            ],
            [
                'id' => 'tables',
                'title' => $t->trans('design.ui.group_tables'),
                'lead' => $t->trans('design.ui.group_tables_lead'),
                'demos' => [
                    $this->uiDemo(
                        'table',
                        'ui()->column() + ui()->table()',
                        'ui()->table(list<TableColumn> $columns, iterable $rows, array $options = []): string',
                        <<<'PHP'
ui()->table([
    ui()->column('name', __('common.name'), [
        'sortable' => true,
        'cell' => 'user', // text|badge|html|user|mute|num
    ]),
    ui()->column('status', __('common.status'), [
        'cell' => 'badge',
        'tone' => fn ($row) => $row['tone'],
    ]),
], $rows, [
    'list_query' => $list,
    'checkable' => true,
    'row_id' => fn ($row) => (string) $row['id'],
    'actions' => fn ($row) => [
        ['href' => '#', 'label' => __('common.edit'), 'icon' => 'edit'],
    ],
    'footer' => true,
]);
PHP,
                        '<div class="list-shell list-shell--fit" x-data="dataTable" data-table-id="ui-kit-table" data-page-ids=\'["ada@gereq.cz","alan@gereq.cz","grace@gereq.cz"]\' data-actions-default="1">'
                        . $table
                        . '</div>',
                    ),
                    $this->uiDemo(
                        'cells',
                        'cell types: mute / num / html / thumb',
                        "ui()->column(..., ['cell' => 'mute'|'num'|'html', 'render' => …])",
                        <<<'PHP'
ui()->column('sku', 'SKU', ['cell' => 'mute']);
ui()->column('price', 'Price', ['cell' => 'num']);
ui()->column('note', 'Note', ['cell' => 'html']);
ui()->column('product', 'Product', [
    'render' => fn ($row) => ui()->partial('ui/table_thumb', …) . …,
]);
PHP,
                        $cellsTable,
                    ),
                    $this->uiDemo(
                        'empty-table',
                        'ui()->table() — empty state',
                        'empty_title / empty_hint options',
                        <<<'PHP'
ui()->table($columns, [], [
    'empty_title' => __('design.tables.empty_heading'),
    'empty_hint' => __('design.tables.empty_hint'),
]);
PHP,
                        $emptyTable,
                    ),
                    $this->uiDemo(
                        'sort-th',
                        'ui()->sortTh()',
                        'ui()->sortTh(array $data): string',
                        <<<'PHP'
ui()->sortTh([
    'label' => __('common.name'),
    'column' => 'name',
    'sort' => $list->sort,
    'dir' => $list->dir,
    'hash' => 'tabulky',
]);
PHP,
                        $sortTh,
                    ),
                    $this->uiDemo(
                        'table-footer',
                        'ui()->tableFooter()',
                        'ui()->tableFooter(array $data): string',
                        <<<'PHP'
ui()->tableFooter([
    'total' => 48,
    'page' => 2,
    'pages' => 6,
    'perPage' => 8,
    'sort' => 'name',
    'dir' => 'asc',
]);
PHP,
                        $footer,
                    ),
                    $this->uiDemo(
                        'row-actions',
                        'ui()->partial(\'ui/row_action\')',
                        'ui/row_action.html.twig · variants by icon',
                        <<<'PHP'
ui()->partial('ui/row_action', [
    'href' => url('…'),
    'label' => __('common.edit'),
    'icon' => 'edit', // eye→primary, edit→success, trash→danger
]);
PHP,
                        $rowActions,
                    ),
                    $this->uiDemo(
                        'table-thumb',
                        'ui()->partial(\'ui/table_thumb\')',
                        'ui()->partial(\'ui/table_thumb\', [\'placeholder\' => true, …])',
                        <<<'PHP'
ui()->partial('ui/table_thumb', [
    'src' => null,
    'placeholder' => true,
    'width' => 48,
    'height' => 48,
]);
PHP,
                        $thumbs,
                    ),
                ],
            ],
            [
                'id' => 'chrome',
                'title' => $t->trans('design.ui.group_chrome'),
                'lead' => $t->trans('design.ui.group_chrome_lead'),
                'demos' => [
                    $this->uiDemo(
                        'buttons',
                        '.btn variants',
                        'CSS · resources/css/kernel/buttons.css',
                        <<<'HTML'
<button class="btn btn--primary">primary</button>
<button class="btn btn--soft">soft</button>
<button class="btn btn--danger btn--sm">danger sm</button>
HTML,
                        $buttons,
                    ),
                    $this->uiDemo(
                        'alerts',
                        '.alert',
                        'CSS · resources/css/kernel/feedback.css',
                        <<<'HTML'
<div class="alert alert--success">Saved</div>
<div class="alert alert--warn">Check this</div>
<div class="alert alert--danger">Failed</div>
HTML,
                        $alerts,
                    ),
                    $this->uiDemo(
                        'toast',
                        'window.gereq.toast()',
                        "window.gereq.toast(message, 'success'|'error'|'info')",
                        <<<'JS'
window.gereq.toast('Saved', 'success');
window.gereq.toast('Failed', 'error');
JS,
                        $toasts,
                    ),
                    $this->uiDemo(
                        'panel',
                        '.panel / ui/form_panel',
                        'HTML · or {% embed \'ui/form_panel.html.twig\' %}',
                        <<<'TWIG'
{% embed 'ui/form_panel.html.twig' with { title: 'Settings' } %}
  {% block body %}…{% endblock %}
{% endembed %}
TWIG,
                        $panel,
                    ),
                    $this->uiDemo(
                        'bulk-bar',
                        '.bulk-bar',
                        'list-shell companion for multi-select',
                        <<<'HTML'
<div class="bulk-bar">
  <div class="bulk-bar__meta"><strong>3</strong> selected</div>
  <div class="bulk-bar__actions">…</div>
</div>
HTML,
                        $bulk,
                    ),
                ],
            ],
            [
                'id' => 'partials',
                'title' => $t->trans('design.ui.group_partials'),
                'lead' => $t->trans('design.ui.group_partials_lead'),
                'demos' => [
                    $this->uiDemo(
                        'partial-api',
                        'ui()->partial() / ui()->component()',
                        'ui()->partial(string $template, array $data = []): string',
                        <<<'PHP'
ui()->partial('ui/breadcrumbs', ['items' => […]]);
ui()->component('badge', ['label' => 'OK', 'tone' => 'ok']);
ui_render('badge', ['label' => 'OK', 'tone' => 'ok']); // Twig
PHP,
                        '<div class="grq-stack grq-stack--sm">'
                        . '<p class="grq-text-meta">' . e($t->trans('design.ui.demo_partial_hint')) . '</p>'
                        . '<ul class="grq-text-meta" style="margin:0;padding-left:1.2rem">'
                        . '<li><code class="design-code">ui/form_panel</code></li>'
                        . '<li><code class="design-code">ui/error-summary</code></li>'
                        . '<li><code class="design-code">ui/ares_subject_suggest</code></li>'
                        . '<li><code class="design-code">ui/ares_address_suggest</code></li>'
                        . '<li><code class="design-code">ui/confirm_dialog</code></li>'
                        . '<li><code class="design-code">ui/image_viewer</code></li>'
                        . '<li><code class="design-code">ui/field</code></li>'
                        . '</ul></div>',
                    ),
                    $this->uiDemo(
                        'table-toggle',
                        'ui()->partial(\'ui/table_toggle\')',
                        'AJAX publish / active switch in tables',
                        <<<'PHP'
ui()->partial('ui/table_toggle', [
    'action' => url('admin.…'),
    'on' => $item->isActive(),
]);
PHP,
                        $tableToggle,
                    ),
                    $this->uiDemo(
                        'error-summary',
                        'ui/error-summary pattern',
                        'ui()->partial(\'ui/error-summary\', [\'form\' => \'…\'])',
                        <<<'PHP'
ui()->partial('ui/error-summary', ['form' => 'users.edit']);
PHP,
                        $errorSummary,
                    ),
                    $this->uiDemo(
                        'macros',
                        'Twig macros',
                        "{% import 'ui/macros.html.twig' as ui %}",
                        <<<'TWIG'
{% import 'ui/macros.html.twig' as ui %}
{{ ui.badge('TOP', 'signal') }}
{{ ui.empty('Title', 'Hint') }}
TWIG,
                        $macros,
                    ),
                    $this->uiDemo(
                        'forms-api',
                        'forms()',
                        'forms()->render($entity) · FormField attributes',
                        <<<'PHP'
forms()->render($entity);
forms()->render($entity, ['group' => 'basic']);
forms()->requiredFields('customers.save');
PHP,
                        $formsApi,
                    ),
                ],
            ],
            [
                'id' => 'states',
                'title' => $t->trans('design.ui.group_states'),
                'lead' => $t->trans('design.ui.group_states_lead'),
                'demos' => [
                    $this->uiDemo(
                        'input-states',
                        'readonly / disabled / color',
                        'ui()->input([\'readonly\'|\'disabled\'|\'type\' => \'color\'])',
                        <<<'PHP'
ui()->input(['name' => 'x', 'readonly' => true, 'form' => null]);
ui()->input(['name' => 'y', 'disabled' => true, 'form' => null]);
ui()->input(['name' => 'c', 'type' => 'color', 'value' => '#625bff', 'form' => null]);
PHP,
                        '<form class="grq-stack grq-stack--md" onsubmit="return false">' . $inputStates . '</form>',
                    ),
                    $this->uiDemo(
                        'grid-span',
                        'ui()->cell() spans',
                        "ui()->cell(\$html, 1|2|3|'full')",
                        <<<'PHP'
ui()->grid(3,
    ui()->cell('A', 1)
    . ui()->cell('B', 2)
    . ui()->cell('Full width', 'full'),
);
PHP,
                        $gridSpan,
                    ),
                ],
            ],
        ];
    }

    /**
     * @return array{id: string, title: string, signature: string, code: string, html: string}
     */
    private function uiDemo(string $id, string $title, string $signature, string $code, string $html): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'signature' => $signature,
            'code' => trim($code) . "\n",
            'html' => $html,
        ];
    }
}
