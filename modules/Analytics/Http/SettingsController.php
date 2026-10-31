<?php

declare(strict_types=1);

namespace Modules\Analytics\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Router;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Analytics\Application\AnalyticsConfig;
use Modules\Analytics\Application\PurgeService;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * `admin.analytics.settings` — the module's own settings form and the retention dry run.
 *
 * `POST /admin/analytics/settings/purge-preview` requires `analytics.purge`, runs
 * {@see PurgeService::purge()} in dry-run mode, writes an audit entry and renders the counts.
 * Mutating forms keep CSRF (`csrf_field()` in the template).
 */
final class SettingsController extends AdminPageController
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        Authenticator $auth,
        Menu $menu,
        CommandPalette $palette,
        TranslatorInterface $translator,
        private readonly Settings $settings,
        private readonly PurgeService $purge,
        private readonly Auditor $auditor,
    ) {
        parent::__construct($twig, $router, $requestStack, $auth, $menu, $palette, $translator);
    }

    public function index(): Response
    {
        return $this->page(null);
    }

    public function save(Request $request): Response
    {
        foreach (AnalyticsConfig::settingDefinitions() as $key => $definition) {
            $field = str_replace('.', '__', $key);
            $posted = $request->request->get($field);

            if ($definition['type'] === SettingRecord::TYPE_BOOLEAN) {
                // A checkbox is simply absent when unchecked, so an absent value means false.
                $this->settings->set($key, $posted === '1' || $posted === 'on' || $posted === 'true', $definition['type'], AnalyticsConfig::MODULE_ID);
                continue;
            }

            // Never zero out a value the form did not post (partial / API-driven saves).
            if (!$request->request->has($field)) {
                continue;
            }

            if ($definition['type'] === SettingRecord::TYPE_INTEGER) {
                $this->settings->set($key, (int) $posted, $definition['type'], AnalyticsConfig::MODULE_ID);
                continue;
            }

            $this->settings->set($key, is_scalar($posted) ? trim((string) $posted) : '', $definition['type'], AnalyticsConfig::MODULE_ID);
        }

        $this->auditor->log('analytics.settings.updated', 'settings', AnalyticsConfig::MODULE_ID);

        return $this->redirect('admin.analytics.settings');
    }

    public function purgePreview(Request $request): Response
    {
        $days = $request->request->get('retention_days');
        $preview = $this->purge->purge(is_numeric($days) ? (int) $days : null, true);
        $this->auditor->log('analytics.purge.preview', 'analytics', null, null, $preview);

        return $this->page($preview);
    }

    /**
     * @param array<string, mixed>|null $purgePreview
     */
    private function page(?array $purgePreview): Response
    {
        $definitions = [];
        foreach (AnalyticsConfig::settingDefinitions() as $key => $definition) {
            $definitions[$key] = [
                'type' => $definition['type'],
                'label' => AnalyticsConfig::labelKey($key),
                'default' => $definition['default'],
                'module' => AnalyticsConfig::MODULE_ID,
                'options' => [],
                'global' => false,
                'section' => AnalyticsConfig::sectionOf($key),
                'section_label' => AnalyticsConfig::sectionLabelKey(AnalyticsConfig::sectionOf($key)),
            ];
        }

        $values = [];
        foreach ($definitions as $key => $definition) {
            $values[$key] = $this->settings->get($key, $definition['default']);
        }

        return $this->adminPage('@analytics/settings.html.twig', [
            'pageTitle' => $this->translator->trans('analytics.title.settings'),
            'values' => $values,
            'definitions' => $definitions,
            'purgePreview' => $purgePreview,
            'consentHint' => $this->translator->trans('analytics.settings.consent_hint'),
        ]);
    }
}
