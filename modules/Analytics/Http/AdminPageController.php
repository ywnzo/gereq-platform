<?php

declare(strict_types=1);

namespace Modules\Analytics\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Analytics\Application\ChartConfig;
use Modules\Analytics\Application\Repositories\SiteRepository;
use Modules\Analytics\Application\ReportRange;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Shared plumbing for the analytics admin controllers: shell render data
 * (`menu` / `commands` / `user`), the report range and the selected site.
 */
abstract class AdminPageController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        protected readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    /** @param array<string, mixed> $data */
    protected function adminPage(string $view, array $data, int $status = 200): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'activeMenuItemIds' => isset($data['range']) ? ['analytics', 'analytics.overview'] : [],
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]), $status);
    }

    protected function reportRange(Request $request, int $defaultDays = 30): ReportRange
    {
        return ReportRange::resolve(
            $request->query->get('range'),
            $request->query->get('from'),
            $request->query->get('to'),
            $defaultDays,
        );
    }

    /**
     * @param list<array<string, mixed>> $sites
     * @return array<string, mixed>|null
     */
    protected function selectedSite(Request $request, array $sites): ?array
    {
        $requested = trim((string) $request->query->get('site', ''));
        if ($requested !== '') {
            foreach ($sites as $site) {
                if ((string) ($site['id'] ?? '') === $requested) {
                    return $site;
                }
            }
        }

        foreach ($sites as $site) {
            if (($site['enabled'] ?? false) === true) {
                return $site;
            }
        }

        return $sites[0] ?? null;
    }

    /**
     * Run an aggregate query defensively: a report page must still render when the analytics
     * tables are empty or missing (module not migrated yet).
     *
     * @template T
     * @param callable(): T $callback
     * @param T $default
     * @return T
     */
    protected function safe(callable $callback, mixed $default): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            // Class name only: DBAL messages can carry SQL fragments.
            error_log('[analytics.report] ' . $exception::class);

            return $default;
        }
    }

    /**
     * Report table column definitions understood by `@analytics/partials/_table.html.twig`
     * (`rows` are keyed by `column.key`, numeric columns are right aligned by the kernel CSS).
     *
     * @param list<array{key: string, label: string, numeric?: bool}> $columns
     * @return list<array{key: string, label: string, numeric: bool}>
     */
    protected function columns(array $columns): array
    {
        return array_map(static fn (array $column): array => [
            'key' => $column['key'],
            'label' => $column['label'],
            'numeric' => $column['numeric'] ?? false,
        ], $columns);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    protected function rankingChart(ChartConfig $charts, array $rows, string $metric, string $label, string $color = '#47c2ff'): array
    {
        return $rows === [] ? [] : $charts->ranking($rows, $metric, $label, $color);
    }

    /**
     * Human readable duration for report tables.
     */
    protected function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0 s';
        }
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $minutes > 0 ? $minutes . ' min ' . $rest . ' s' : $rest . ' s';
    }

    /** @param array<string, mixed> $site */
    protected function siteName(array $site): string
    {
        $name = trim((string) ($site['name'] ?? ''));

        return $name !== '' ? $name : (string) ($site['key'] ?? '');
    }

    /** @return list<array<string, mixed>> */
    protected function allSites(SiteRepository $sites): array
    {
        return $this->safe(static fn (): array => $sites->all(), []);
    }
}
