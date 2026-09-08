<?php

declare(strict_types=1);

namespace Gereq\Admin\Menu;

use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final class Menu
{
    /** @var list<MenuItem> */
    private array $items = [];

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

        // Sidebar spojuje sousední stejné group — proto nejdřív shluknout skupiny.
        // Prázdná group = položky mimo sekce (např. Přehled) — vždy první.
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

    /** @return list<MenuItem> */
    public function all(): array
    {
        return $this->items;
    }

    private function resolveUrl(MenuItem $item, Router $router, ?Request $request): string
    {
        if ($item->url !== null) {
            return $item->url;
        }

        if ($item->route !== null && $router->has($item->route)) {
            return $router->url($item->route, [], $request);
        }

        return '#';
    }
}
