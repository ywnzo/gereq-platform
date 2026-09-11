<?php

declare(strict_types=1);

namespace Gereq\Admin\Menu;

use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class Menu
{
    /** @var list<MenuItem> */
    private array $items = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function add(MenuItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @return list<array{group: ?string, nodes: list<array{item: MenuItem, children: list<MenuItem>, url: string}>}>
     */
    public function tree(?User $user, Router $router, ?Request $request = null): array
    {
        $visible = array_filter(
            $this->items,
            static function (MenuItem $item) use ($user): bool {
                if ($item->hidden) {
                    return false;
                }
                if ($item->permission === null) {
                    return true;
                }

                return $user !== null && $user->can($item->permission);
            }
        );

        $children = [];
        /** @var array<string, list<MenuItem>> $rootsByGroup */
        $rootsByGroup = [];

        foreach ($visible as $item) {
            if ($item->parent !== null) {
                $children[$item->parent][] = $item;
                continue;
            }
            $group = $item->group ?? '';
            $rootsByGroup[$group][] = $item;
        }

        // Sidebar merges adjacent same groups — cluster groups first.
        // Empty group = items outside sections (e.g. Overview) — always first.
        $groupRanks = [];
        foreach ($rootsByGroup as $group => $roots) {
            usort($roots, static fn (MenuItem $a, MenuItem $b): int => $a->order <=> $b->order ?: strcmp($a->id, $b->id));
            $rootsByGroup[$group] = $roots;
            $groupRanks[$group] = min(array_map(static fn (MenuItem $i): int => $i->order, $roots));
        }

        uksort(
            $rootsByGroup,
            static function (string $a, string $b) use ($groupRanks): int {
                if ($a === '') {
                    return -1;
                }
                if ($b === '') {
                    return 1;
                }

                return ($groupRanks[$a] <=> $groupRanks[$b]) ?: strcmp($a, $b);
            },
        );

        foreach ($children as $parentId => $list) {
            usort($list, static fn (MenuItem $a, MenuItem $b): int => $a->order <=> $b->order ?: strcmp($a->id, $b->id));
            $children[$parentId] = $list;
        }

        $sections = [];
        foreach ($rootsByGroup as $group => $roots) {
            $nodes = [];
            foreach ($roots as $root) {
                $nodes[] = [
                    'item' => $root,
                    'children' => $children[$root->id] ?? [],
                    'url' => $this->resolveUrl($root, $router, $request),
                ];
            }
            $sections[] = [
                'group' => $group !== '' ? $group : null,
                'nodes' => $nodes,
            ];
        }

        return $sections;
    }

    /**
     * Trail: Overview → module(s) → current page.
     *
     * @return list<array{label: string, url?: string|null}>
     */
    public function breadcrumbsFor(
        string $routeName,
        Router $router,
        ?Request $request = null,
        ?string $currentLabel = null,
    ): array {
        $overviewLabel = $this->translator->trans('nav.overview');
        $overviewUrl = $router->has('admin.dashboard')
            ? $router->url('admin.dashboard', [], $request)
            : null;

        if ($routeName === 'admin.dashboard' || $routeName === '') {
            return [['label' => $overviewLabel]];
        }

        $matched = $this->matchItem($routeName);
        if ($matched === null) {
            $fallback = [['label' => $overviewLabel, 'url' => $overviewUrl]];
            if ($currentLabel !== null && $currentLabel !== '' && $currentLabel !== $overviewLabel) {
                $fallback[] = ['label' => $currentLabel];
            }

            return $fallback;
        }

        /** @var array<string, MenuItem> $byId */
        $byId = [];
        foreach ($this->items as $item) {
            $byId[$item->id] = $item;
        }

        $anchor = $matched;
        if ($matched->hidden && $matched->parent !== null && isset($byId[$matched->parent])) {
            $anchor = $byId[$matched->parent];
        }

        $chain = [];
        $cursor = $anchor;
        $guard = 0;
        while ($cursor !== null && $guard < 20) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->parent !== null ? ($byId[$cursor->parent] ?? null) : null;
            ++$guard;
        }

        $onAnchorIndex = $anchor->route !== null && $routeName === $anchor->route;
        $crumbs = [['label' => $overviewLabel, 'url' => $overviewUrl]];

        foreach ($chain as $item) {
            if ($item->route === 'admin.dashboard') {
                continue;
            }
            $label = $this->translator->trans($item->label);
            $isCurrent = $onAnchorIndex && $item->id === $anchor->id;
            $crumbs[] = [
                'label' => $label,
                'url' => $isCurrent ? null : $this->resolveUrl($item, $router, $request),
            ];
        }

        if (!$onAnchorIndex && $currentLabel !== null && $currentLabel !== '') {
            $lastLabel = $crumbs[array_key_last($crumbs)]['label'] ?? '';
            if ($currentLabel !== $lastLabel && $currentLabel !== $overviewLabel) {
                $crumbs[] = ['label' => $currentLabel];
            }
        }

        // Last crumb is never a link.
        $last = array_key_last($crumbs);
        if ($last !== null) {
            unset($crumbs[$last]['url']);
            $crumbs = array_values($crumbs);
        }

        return $crumbs;
    }

    /** @return list<MenuItem> */
    public function all(): array
    {
        return $this->items;
    }

    private function matchItem(string $routeName): ?MenuItem
    {
        foreach ($this->items as $item) {
            if ($item->route === $routeName) {
                return $item;
            }
        }

        if (preg_match('/^(.*)\.[^.]+$/', $routeName, $matches) === 1) {
            $indexRoute = $matches[1] . '.index';
            foreach ($this->items as $item) {
                if ($item->route === $indexRoute) {
                    return $item;
                }
            }
        }

        $best = null;
        $bestLen = -1;
        foreach ($this->items as $item) {
            if ($item->route === null || $item->route === '') {
                continue;
            }
            if (!str_starts_with($routeName, $item->route . '.')) {
                continue;
            }
            $len = strlen($item->route);
            if ($len > $bestLen || ($len === $bestLen && !$item->hidden && ($best?->hidden ?? false))) {
                $best = $item;
                $bestLen = $len;
            }
        }

        return $best;
    }

    private function resolveUrl(MenuItem $item, Router $router, ?Request $request): string
    {
        if ($item->url !== null) {
            return \Gereq\Support\UrlPrefix::path($item->url);
        }

        if ($item->route !== null && $router->has($item->route)) {
            return $router->url($item->route, [], $request);
        }

        return '#';
    }
}
