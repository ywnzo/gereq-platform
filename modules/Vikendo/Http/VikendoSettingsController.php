<?php

declare(strict_types=1);

namespace Modules\Vikendo\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Queue\Queue;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Vikendo\Application\VikendoClient;
use Modules\Vikendo\Application\VikendoImportConfig;
use Modules\Vikendo\Application\VikendoImportJob;
use Modules\Vikendo\Application\VikendoLog;
use Modules\Events\Application\Import\EventImportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class VikendoSettingsController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly Settings $settings,
        private readonly VikendoImportConfig $importConfig,
        private readonly VikendoClient $client,
        private readonly Queue $queue,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function importPage(): Response
    {
        return $this->page('@vikendo/import.html.twig', [
            'pageTitle' => $this->translator->trans('vikendo.nav.import'),
            'vikendoTab' => 'import',
            'perPage' => $this->importConfig->perPage(),
            'maxPages' => $this->importConfig->maxPages(),
            'startFrom' => (string) ($this->importConfig->startFrom() ?? ''),
            'importEnabled' => $this->importConfig->importEnabled(),
            'canImport' => class_exists(EventImportService::class),
            'selectedDistricts' => $this->importConfig->districts(),
            'importRunning' => VikendoLog::apparentlyRunning(),
        ]);
    }

    public function saveImport(Request $request): Response
    {
        $this->settings->set(
            'vikendo.import_enabled',
            $this->bool($request, 'import_enabled'),
            SettingRecord::TYPE_BOOLEAN,
            'vikendo',
        );
        $perPage = (int) $request->request->get('per_page', 100);
        $this->settings->set(
            'vikendo.per_page',
            max(1, min(100, $perPage > 0 ? $perPage : 100)),
            SettingRecord::TYPE_INTEGER,
            'vikendo',
        );
        $maxPages = (int) $request->request->get('max_pages', 40);
        $this->settings->set(
            'vikendo.max_pages',
            max(1, min(100, $maxPages > 0 ? $maxPages : 40)),
            SettingRecord::TYPE_INTEGER,
            'vikendo',
        );
        $this->settings->set(
            'vikendo.start_from',
            trim((string) $request->request->get('start_from', '')),
            SettingRecord::TYPE_STRING,
            'vikendo',
        );

        $this->auditor->log('vikendo.settings.updated', 'module', 'vikendo', [], [
            'section' => 'import',
        ]);
        $this->flash('success', $this->translator->trans('vikendo.flash.saved'));

        return $this->redirect('admin.vikendo.import');
    }

    public function districtsPage(): Response
    {
        $catalog = [];
        $regions = [];
        $catalogError = null;
        try {
            $catalog = $this->client->districtsCatalog();
            $regions = $this->client->regionsCatalog();
        } catch (\Throwable $e) {
            $catalogError = $e->getMessage();
        }

        $regionNames = [];
        foreach ($regions as $region) {
            $regionNames[$region['eid']] = $region['name'];
        }

        $selected = $this->importConfig->districtEids();
        $selectedMap = array_fill_keys($selected, true);

        $rows = [];
        $seen = [];
        foreach ($catalog as $row) {
            $seen[$row['eid']] = true;
            $rows[] = [
                'eid' => $row['eid'],
                'name' => $row['name'],
                'region_eid' => $row['region_eid'],
                'region_name' => $regionNames[$row['region_eid']] ?? $row['region_eid'],
                'selected' => isset($selectedMap[$row['eid']]),
                'plzensky' => $row['region_eid'] === VikendoClient::REGION_PLZENSKY,
            ];
        }
        // Okresy uložené, ale chybějící v aktuálním katalogu API.
        foreach ($this->importConfig->districts() as $saved) {
            if (isset($seen[$saved['eid']])) {
                continue;
            }
            $rows[] = [
                'eid' => $saved['eid'],
                'name' => $saved['name'],
                'region_eid' => $saved['region_eid'],
                'region_name' => $regionNames[$saved['region_eid']] ?? ($saved['region_eid'] !== '' ? $saved['region_eid'] : '—'),
                'selected' => true,
                'plzensky' => $saved['region_eid'] === VikendoClient::REGION_PLZENSKY,
            ];
        }

        return $this->page('@vikendo/districts.html.twig', [
            'pageTitle' => $this->translator->trans('vikendo.nav.districts'),
            'vikendoTab' => 'districts',
            'districtRows' => $rows,
            'regions' => $regions,
            'selectedCount' => count($selected),
            'catalogError' => $catalogError,
            'regionPlzenskyEid' => VikendoClient::REGION_PLZENSKY,
        ]);
    }

    public function saveDistricts(Request $request): Response
    {
        $eids = $request->request->all('districts');
        if (!is_array($eids)) {
            $eids = [];
        }
        $eids = array_values(array_unique(array_map('strval', $eids)));

        $catalogByEid = [];
        try {
            foreach ($this->client->districtsCatalog() as $row) {
                $catalogByEid[$row['eid']] = $row;
            }
        } catch (\Throwable) {
            // fallback na výchozí mapu
        }

        $rows = [];
        foreach ($eids as $eid) {
            if (isset($catalogByEid[$eid])) {
                $rows[] = [
                    'eid' => $eid,
                    'name' => $catalogByEid[$eid]['name'],
                    'region_eid' => $catalogByEid[$eid]['region_eid'],
                ];
                continue;
            }
            if (isset(VikendoImportConfig::DISTRICTS[$eid])) {
                $rows[] = [
                    'eid' => $eid,
                    'name' => VikendoImportConfig::DISTRICTS[$eid],
                    'region_eid' => VikendoClient::REGION_PLZENSKY,
                ];
            }
        }

        // Ruční přidání (EID + název).
        $manualEid = trim((string) $request->request->get('manual_eid', ''));
        $manualName = trim((string) $request->request->get('manual_name', ''));
        $manualRegion = trim((string) $request->request->get('manual_region_eid', ''));
        if ($manualEid !== '' && preg_match('/^\d{3,6}$/', $manualEid) === 1) {
            $rows[] = [
                'eid' => $manualEid,
                'name' => $manualName !== ''
                    ? $manualName
                    : ($catalogByEid[$manualEid]['name'] ?? VikendoImportConfig::DISTRICTS[$manualEid] ?? ('Okres ' . $manualEid)),
                'region_eid' => $manualRegion !== ''
                    ? $manualRegion
                    : ($catalogByEid[$manualEid]['region_eid'] ?? ''),
            ];
        }

        $this->importConfig->saveDistricts($rows);
        $this->auditor->log('vikendo.settings.updated', 'module', 'vikendo', [], [
            'section' => 'districts',
            'districts' => $this->importConfig->districtEids(),
        ]);
        $this->flash('success', $this->translator->trans('vikendo.flash.saved'));

        return $this->redirect('admin.vikendo.districts');
    }

    public function catalogPage(): Response
    {
        $categories = [];
        $regions = [];
        $catalogError = null;
        try {
            $categories = $this->client->categoriesCatalog();
            $regions = $this->client->regionsCatalog();
        } catch (\Throwable $e) {
            $catalogError = $e->getMessage();
        }

        $usedRegionEids = [];
        foreach ($this->importConfig->districts() as $d) {
            if ($d['region_eid'] !== '') {
                $usedRegionEids[$d['region_eid']] = true;
            }
        }
        if ($usedRegionEids === []) {
            $usedRegionEids[VikendoClient::REGION_PLZENSKY] = true;
        }

        return $this->page('@vikendo/catalog.html.twig', [
            'pageTitle' => $this->translator->trans('vikendo.nav.catalog'),
            'vikendoTab' => 'catalog',
            'categories' => $categories,
            'regions' => $regions,
            'usedRegionEids' => $usedRegionEids,
            'catalogError' => $catalogError,
        ]);
    }

    public function apiPage(): Response
    {
        return $this->page('@vikendo/api.html.twig', [
            'pageTitle' => $this->translator->trans('vikendo.nav.api'),
            'vikendoTab' => 'api',
            'baseUrl' => $this->client->baseUrl(),
            'timeout' => $this->client->timeout(),
            'hasToken' => $this->client->authToken() !== '',
            'defaultRegionEid' => $this->client->defaultRegionEid(),
        ]);
    }

    public function saveApi(Request $request): Response
    {
        $baseUrl = trim((string) $request->request->get('base_url', VikendoClient::DEFAULT_BASE_URL));
        $this->settings->set(
            'vikendo.base_url',
            $baseUrl !== '' ? rtrim($baseUrl, '/') : VikendoClient::DEFAULT_BASE_URL,
            SettingRecord::TYPE_STRING,
            'vikendo',
        );
        $timeout = (int) $request->request->get('timeout', 20);
        $this->settings->set(
            'vikendo.timeout',
            max(3, min(120, $timeout > 0 ? $timeout : 20)),
            SettingRecord::TYPE_INTEGER,
            'vikendo',
        );
        $regionEid = trim((string) $request->request->get('default_region_eid', VikendoClient::REGION_PLZENSKY));
        $this->settings->set(
            'vikendo.default_region_eid',
            $regionEid !== '' ? $regionEid : VikendoClient::REGION_PLZENSKY,
            SettingRecord::TYPE_STRING,
            'vikendo',
        );
        $token = trim((string) $request->request->get('auth_token', ''));
        if ($token !== '') {
            $this->settings->set(
                'vikendo.auth_token',
                $token,
                SettingRecord::TYPE_ENCRYPTED,
                'vikendo',
            );
        }

        $this->auditor->log('vikendo.settings.updated', 'module', 'vikendo', [], [
            'section' => 'api',
        ]);
        $this->flash('success', $this->translator->trans('vikendo.flash.saved'));

        return $this->redirect('admin.vikendo.api');
    }

    public function logPage(): Response
    {
        return $this->page('@vikendo/log.html.twig', [
            'pageTitle' => $this->translator->trans('vikendo.nav.log'),
            'vikendoTab' => 'log',
            'importRunning' => VikendoLog::apparentlyRunning(),
            'canImport' => class_exists(EventImportService::class),
            'importEnabled' => $this->importConfig->importEnabled(),
            'logPollUrl' => $this->router->url('admin.vikendo.log.tail'),
        ]);
    }

    public function logTail(Request $request): JsonResponse
    {
        $offset = (int) $request->query->get('offset', -1);
        $tail = VikendoLog::tail($offset);

        return new JsonResponse([
            'offset' => $tail['offset'],
            'size' => $tail['size'],
            'lines' => $tail['lines'],
            'truncated' => $tail['truncated'],
            'running' => VikendoLog::apparentlyRunning(),
        ]);
    }

    public function logClear(Request $request): Response
    {
        VikendoLog::clear();
        VikendoLog::info('log.cleared', ['via' => 'admin']);
        $this->flash('success', $this->translator->trans('vikendo.flash.log_cleared'));

        return $this->redirect('admin.vikendo.log');
    }

    /** @deprecated Keep route name admin.vikendo.settings for module settings_route. */
    public function edit(): Response
    {
        return $this->importPage();
    }

    public function import(Request $request): Response
    {
        if (!class_exists(EventImportService::class)) {
            $this->flash('error', $this->translator->trans('vikendo.flash.events_missing'));

            return $this->redirect('admin.vikendo.import');
        }
        if (!$this->importConfig->importEnabled()) {
            $this->flash('error', $this->translator->trans('vikendo.flash.import_disabled'));

            return $this->redirect('admin.vikendo.import');
        }

        VikendoLog::info('queue.dispatch', [
            'source' => 'vikendo',
            'archive_missing' => true,
            'via' => 'admin.import',
            'districts' => $this->importConfig->districtEids(),
        ]);
        $this->queue->dispatch(new VikendoImportJob('vikendo', true));
        $this->auditor->log('vikendo.import.queued', 'module', 'vikendo');
        $this->flash('success', $this->translator->trans('vikendo.flash.queued'));

        return $this->redirect('admin.vikendo.log');
    }

    private function bool(Request $request, string $name): bool
    {
        return in_array((string) $request->request->get($name, '0'), ['1', 'true', 'on', 'yes'], true);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
