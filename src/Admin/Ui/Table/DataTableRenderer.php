<?php

declare(strict_types=1);

namespace Gereq\Admin\Ui\Table;

use Gereq\Http\ListQuery;
use Twig\Environment;

/**
 * Renders a declarative admin data table (sort headers, cells, optional actions/footer).
 */
final class DataTableRenderer
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    /**
     * @param list<TableColumn> $columns
     * @param iterable<mixed> $rows
     * @param array{
     *     sort?: string,
     *     dir?: string,
     *     search?: string,
     *     query?: array<string, scalar|null>,
     *     list_query?: ListQuery|null,
     *     total?: int|null,
     *     empty_title?: string|null,
     *     empty_hint?: string|null,
     *     class?: string|null,
     *     wrap_class?: string|null,
     *     checkable?: bool,
     *     row_id?: callable(mixed): string,
     *     row_checkable?: callable(mixed): bool,
     *     row_search?: callable(mixed): string,
     *     actions?: callable(mixed): list<array{
     *         href?: string|null,
     *         label: string,
     *         icon?: string,
     *         variant?: string,
     *         type?: 'link'|'destroy',
     *         action?: string,
     *         confirm?: string,
     *         confirm_title?: string
     *     }>,
     *     footer?: bool,
     *     hash?: string
     * } $options
     */
    public function render(array $columns, iterable $rows, array $options = []): string
    {
        $listQuery = $options['list_query'] ?? null;
        $sort = (string) ($options['sort'] ?? ($listQuery instanceof ListQuery ? $listQuery->sort : ''));
        $dir = (string) ($options['dir'] ?? ($listQuery instanceof ListQuery ? $listQuery->dir : 'asc'));
        $search = (string) ($options['search'] ?? ($listQuery instanceof ListQuery ? $listQuery->search : ''));
        $query = $options['query'] ?? ($listQuery instanceof ListQuery ? $listQuery->params(['page' => null, 'sort' => null, 'dir' => null]) : []);
        if (!is_array($query)) {
            $query = [];
        }

        $rowList = [];
        foreach ($rows as $row) {
            $rowList[] = $row;
        }

        /** @var (callable(mixed): list<array<string, mixed>>)|null $actions */
        $actions = $options['actions'] ?? null;
        /** @var (callable(mixed): string)|null $rowId */
        $rowId = $options['row_id'] ?? null;
        /** @var (callable(mixed): bool)|null $rowCheckable */
        $rowCheckable = $options['row_checkable'] ?? null;
        /** @var (callable(mixed): string)|null $rowSearch */
        $rowSearch = $options['row_search'] ?? null;

        $cells = [];
        foreach ($rowList as $index => $row) {
            $cells[$index] = [];
            foreach ($columns as $column) {
                $cells[$index][$column->key] = $this->renderCell($column, $row);
            }
        }

        $actionCells = [];
        if ($actions !== null) {
            foreach ($rowList as $index => $row) {
                $defs = $actions($row);
                $actionCells[$index] = is_array($defs) ? $defs : [];
            }
        }

        $ids = [];
        if ($rowId !== null) {
            foreach ($rowList as $index => $row) {
                $ids[$index] = (string) $rowId($row);
            }
        }

        $checkableFlags = [];
        foreach ($rowList as $index => $row) {
            $checkableFlags[$index] = $rowCheckable === null ? true : (bool) $rowCheckable($row);
        }

        $searchBlobs = [];
        if ($rowSearch !== null) {
            foreach ($rowList as $index => $row) {
                $searchBlobs[$index] = mb_strtolower((string) $rowSearch($row));
            }
        }

        $total = $options['total'] ?? count($rowList);
        $showFooter = (bool) ($options['footer'] ?? ($listQuery instanceof ListQuery && (int) $total > 0));

        return $this->twig->render('ui/components/data_table.html.twig', [
            'columns' => $columns,
            'rows' => $rowList,
            'cells' => $cells,
            'action_cells' => $actionCells,
            'row_ids' => $ids,
            'row_checkable' => $checkableFlags,
            'row_search' => $searchBlobs,
            'has_actions' => $actions !== null,
            'checkable' => (bool) ($options['checkable'] ?? false),
            'sort' => $sort,
            'dir' => $dir === 'desc' ? 'desc' : 'asc',
            'search' => $search,
            'query' => $query,
            'table_class' => $options['class'] ?? null,
            'wrap_class' => $options['wrap_class'] ?? null,
            'empty_title' => $options['empty_title'] ?? null,
            'empty_hint' => $options['empty_hint'] ?? null,
            'show_footer' => $showFooter,
            'total' => (int) $total,
            'page' => $listQuery instanceof ListQuery ? $listQuery->page : (int) ($options['page'] ?? 1),
            'pages' => $listQuery instanceof ListQuery ? $listQuery->pages((int) $total) : (int) ($options['pages'] ?? 1),
            'per_page' => $listQuery instanceof ListQuery ? $listQuery->perPage : (int) ($options['per_page'] ?? count($rowList)),
            'list_query' => $listQuery,
            'hash' => (string) ($options['hash'] ?? ''),
        ]);
    }

    private function renderCell(TableColumn $column, mixed $row): string
    {
        if (is_callable($column->render)) {
            return (string) ($column->render)($row);
        }

        $value = $column->value($row);
        $string = $value === null ? '' : (string) $value;

        return match ($column->cell) {
            'html' => $string,
            'badge' => $this->twig->render('ui/components/badge.html.twig', [
                'label' => $string !== '' ? $string : '—',
                'tone' => $this->resolveTone($column, $row),
            ]),
            'user' => $this->renderUserCell($column, $row, $string),
            'mute' => '<span class="is-mute">' . e($string !== '' ? $string : '—') . '</span>',
            'num' => e($string !== '' ? $string : '—'),
            default => e($string !== '' ? $string : '—'),
        };
    }

    private function resolveTone(TableColumn $column, mixed $row): string
    {
        if (is_callable($column->tone)) {
            $tone = (string) ($column->tone)($row);

            return $tone !== '' ? $tone : 'muted';
        }
        if (is_array($row) && isset($row[$column->key . '_tone'])) {
            return (string) $row[$column->key . '_tone'];
        }

        return 'muted';
    }

    private function renderUserCell(TableColumn $column, mixed $row, string $name): string
    {
        $metaKey = (string) ($column->meta['meta_key'] ?? 'meta');
        $initialsKey = (string) ($column->meta['initials_key'] ?? 'initials');
        $meta = '';
        $initials = '';
        if (is_array($row)) {
            $meta = (string) ($row[$metaKey] ?? '');
            $initials = (string) ($row[$initialsKey] ?? '');
        }
        if ($initials === '' && $name !== '') {
            $parts = preg_split('/\s+/', trim($name)) ?: [];
            $initials = strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
        }

        $email = '';
        $avatarSrc = null;
        if (is_array($row)) {
            $email = (string) ($row[(string) ($column->meta['email_key'] ?? 'email')] ?? '');
            $rawSrc = $row[(string) ($column->meta['avatar_src_key'] ?? 'avatar_src')] ?? null;
            $avatarSrc = is_string($rawSrc) && $rawSrc !== '' ? $rawSrc : null;
        }

        return $this->twig->render('ui/components/table_user_cell.html.twig', [
            'name' => $name !== '' ? $name : '—',
            'meta' => $meta,
            'initials' => $initials !== '' ? $initials : '?',
            'email' => $email !== '' ? $email : null,
            'avatar_src' => $avatarSrc,
            'href' => is_array($row) ? ($row[$column->meta['href_key'] ?? 'href'] ?? null) : null,
        ]);
    }
}
