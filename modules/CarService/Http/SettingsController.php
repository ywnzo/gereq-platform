<?php

declare(strict_types=1);

namespace Modules\CarService\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Module\ModuleManager;
use Gereq\Setting\Settings;
use Modules\CarService\Application\LaborRateCatalog;
use Modules\CarService\Application\PdfTemplateCatalog;
use Modules\CarService\Application\PdfTemplateService;
use Modules\CarService\Application\ServiceDocumentService;
use Modules\CarService\Domain\ServiceOrder;
use Modules\Currency\Application\CurrencyBuilder;
use Modules\Documents\Application\Pdf\PdfRenderer;
use Modules\SearchEngines\Application\SearchEnginesProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SettingsController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly Settings $settings,
        private readonly ModuleManager $modules,
        private readonly CapabilityRegistry $capabilities,
        private readonly PdfTemplateService $pdfTemplates,
        private readonly ServiceDocumentService $serviceDocuments,
        private readonly CurrencyBuilder $money,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        return $this->page('@carservice/settings.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.settings.title'),
            'jobSoftLimitHours' => (string) $this->settings->get('carservice.job_soft_limit_hours', '8'),
            'defaultTeam' => (string) $this->settings->get('carservice.default_team', 'body_shop'),
            'mediaPhases' => (string) $this->settings->get('carservice.media_phases', 'before,progress,paint,after'),
            'marginPercent' => (string) $this->settings->get('carservice.margin_percent', '30'),
            'laborRates' => $this->laborRateValues(),
            'laborRateSuffix' => $this->money->symbol() . '/h',
            'autoIdoklad' => (bool) $this->settings->get('carservice.auto_idoklad', false),
            'marketSources' => $this->selectedMarketSources(),
            'marketEngines' => $this->marketEngineOptions(),
            'teams' => ServiceOrder::TEAMS,
            'pdfTemplates' => $this->pdfTemplateValues(),
            'pdfTypes' => PdfTemplateCatalog::typeMeta(),
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, 'carservice.settings');
        $hours = (string) $data['job_soft_limit_hours'];
        if (!is_numeric($hours) || (float) $hours < 0) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_limit'));

            return $this->redirect('admin.carservice.settings');
        }
        $team = (string) $data['default_team'];
        if (!in_array($team, ServiceOrder::TEAMS, true)) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_team'));

            return $this->redirect('admin.carservice.settings');
        }
        $phases = trim((string) ($data['media_phases'] ?? ''));
        if ($phases === '') {
            $phases = 'before,progress,paint,after';
        }
        $margin = (string) ($data['margin_percent'] ?? '0');
        if (!is_numeric($margin) || (float) $margin < 0 || (float) $margin > 1000) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_margin'));

            return $this->redirect('admin.carservice.settings');
        }
        $this->settings->set('carservice.job_soft_limit_hours', $hours);
        $this->settings->set('carservice.default_team', $team);
        $this->settings->set('carservice.media_phases', $phases);
        $this->settings->set('carservice.margin_percent', number_format((float) $margin, 2, '.', ''));
        if (!$this->persistLaborRates($data)) {
            return $this->redirect('admin.carservice.settings');
        }
        $this->settings->set('carservice.auto_idoklad', !empty($data['auto_idoklad']));
        $this->settings->set('carservice.market_sources', $this->normalizeMarketSources($data['market_sources'] ?? []));

        foreach (PdfTemplateCatalog::allSettingKeys() as $key) {
            $field = PdfTemplateCatalog::formField($key);
            $this->settings->set($key, trim((string) ($data[$field] ?? '')));
        }

        $this->forgetForm('carservice.settings');
        $this->flash('success', $this->translator->trans('carservice.flash.settings_saved'));

        return $this->redirect('admin.carservice.settings');
    }

    /** Zkušební PDF z aktuálních polí formuláře (bez uložení). */
    public function previewPdf(Request $request): Response
    {
        $data = $this->validate($request, 'carservice.pdf_preview');
        $type = (string) ($data['pdf_preview_type'] ?? PdfTemplateCatalog::TYPE_WORKSHEET);
        if (!in_array($type, PdfTemplateCatalog::types(), true)) {
            $type = PdfTemplateCatalog::TYPE_WORKSHEET;
        }

        $brand = $this->pdfTemplates->brandName();
        $docTitle = $this->pdfTemplates->documentTitle($type);
        $chrome = $this->pdfTemplates->chromeFromPosted($type, $data, [
            'brand' => $brand,
            'doc_title' => $docTitle,
        ]);

        $result = $this->serviceDocuments->preview($type, $chrome);
        $filename = 'nahled-' . $type . '.' . $result->extension;
        $mime = $result->isPdf() ? $result->mime : 'text/html; charset=UTF-8';

        return new Response($result->binary, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return array<string, string> field => value
     */
    private function laborRateValues(): array
    {
        $out = [];
        foreach (LaborRateCatalog::definitions() as $rate) {
            $out[$rate['field']] = (string) $this->settings->get($rate['setting'], $rate['default']);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function persistLaborRates(array $data): bool
    {
        $defaultRaw = trim((string) ($data['labor_rate_default'] ?? ''));
        if ($defaultRaw === '' || !is_numeric($defaultRaw) || (float) $defaultRaw < 0) {
            $this->flash('error', $this->translator->trans('carservice.error.invalid_labor_rate'));

            return false;
        }

        foreach (LaborRateCatalog::definitions() as $rate) {
            $raw = trim((string) ($data[$rate['field']] ?? ''));
            if ($raw === '') {
                if ($rate['team'] === null) {
                    $this->flash('error', $this->translator->trans('carservice.error.invalid_labor_rate'));

                    return false;
                }
                $this->settings->set($rate['setting'], '');
                continue;
            }
            if (!is_numeric($raw) || (float) $raw < 0) {
                $this->flash('error', $this->translator->trans('carservice.error.invalid_labor_rate'));

                return false;
            }
            $this->settings->set($rate['setting'], number_format((float) $raw, 2, '.', ''));
        }

        return true;
    }

    /**
     * @return array<string, string> settingKey => html
     */
    private function pdfTemplateValues(): array
    {
        $out = [];
        foreach (PdfTemplateCatalog::allSettingKeys() as $key) {
            $stored = trim((string) $this->settings->get($key, ''));
            if ($key === PdfTemplateCatalog::globalKey(PdfTemplateCatalog::PART_HEADER)) {
                $out[$key] = PdfTemplateCatalog::effectiveTemplate($stored, PdfTemplateCatalog::PART_HEADER);
                continue;
            }
            if ($key === PdfTemplateCatalog::globalKey(PdfTemplateCatalog::PART_FOOTER)) {
                $out[$key] = PdfTemplateCatalog::effectiveTemplate($stored, PdfTemplateCatalog::PART_FOOTER);
                continue;
            }
            $out[$key] = $stored;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function selectedMarketSources(): array
    {
        $raw = trim((string) $this->settings->get('carservice.market_sources', 'heureka,seznam,hledejceny,jama,autodilypro,autokelly'));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $id): string => trim($id),
            explode(',', $raw),
        ), static fn (string $id): bool => $id !== ''));
    }

    /**
     * @param mixed $posted
     */
    private function normalizeMarketSources(mixed $posted): string
    {
        $allowed = ['heureka', 'seznam', 'hledejceny', 'jama', 'autodilypro', 'autokelly', 'mojekolo'];
        $ids = [];
        if (is_array($posted)) {
            foreach ($posted as $id) {
                $id = trim((string) $id);
                if (in_array($id, $allowed, true)) {
                    $ids[] = $id;
                }
            }
        }

        return implode(',', array_values(array_unique($ids)));
    }

    /**
     * @return list<array{id: string, name: string, available: bool}>
     */
    private function marketEngineOptions(): array
    {
        if (!$this->modules->isEnabled('searchengines') || !$this->capabilities->has('searchengines')) {
            return [];
        }
        $cap = $this->capabilities->get('searchengines');
        if (!$cap instanceof SearchEnginesProvider) {
            return [];
        }
        $out = [];
        foreach ($cap->engines() as $engine) {
            $out[] = [
                'id' => $engine['id'],
                'name' => $engine['name'],
                'available' => $engine['available'],
            ];
        }

        return $out;
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
