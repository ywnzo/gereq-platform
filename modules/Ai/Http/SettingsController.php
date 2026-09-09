<?php

declare(strict_types=1);

namespace Modules\Ai\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Ai\Application\Catalog\DefaultModelCatalog;
use Modules\Ai\Application\LlmProviderRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SettingsController extends Controller
{
    /** @var list<string> */
    private const KEYS = [
        'ai.openai_api_key',
        'ai.anthropic_api_key',
        'ai.gemini_api_key',
        'ai.groq_api_key',
        'ai.openrouter_api_key',
        'ai.cerebras_api_key',
        'ai.zai_api_key',
        'ai.deepseek_api_key',
        'ai.ollama_base_url',
    ];

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Settings $settings,
        private readonly LlmProviderRegistry $providers,
        private readonly DefaultModelCatalog $catalog,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        return $this->page();
    }

    public function update(Request $request): Response
    {
        $definitions = $this->settings->definitions();
        foreach (self::KEYS as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }
            $definition = $definitions[$key];
            $posted = $request->request->get(str_replace('.', '__', $key));
            $value = is_scalar($posted) ? (string) $posted : '';
            if ($definition['type'] === SettingRecord::TYPE_ENCRYPTED && $value === '') {
                continue;
            }
            $this->settings->set($key, $value, $definition['type'], $definition['module'] ?? 'ai');
        }
        $this->flash('success', $this->translator->trans('ai.flash.settings_saved'));

        return $this->redirect('admin.ai.settings');
    }

    public function ping(Request $request): Response
    {
        $driver = (string) $request->request->get('driver', 'ollama');
        $llm = $this->providers->llm($driver);
        if ($llm === null) {
            $this->flash('error', 'Unknown driver');
            return $this->redirect('admin.ai.settings');
        }
        $result = $llm->ping();
        $this->flash($result['ok'] ? 'success' : 'error', $result['message']);

        return $this->redirect('admin.ai.settings');
    }

    public function seedCatalog(): Response
    {
        $this->catalog->seed();
        $this->flash('success', $this->translator->trans('ai.flash.catalog_seeded'));

        return $this->redirect('admin.ai.settings');
    }

    /** @param array<string, mixed> $extra */
    private function page(array $extra = []): Response
    {
        $user = $this->auth->user();

        return $this->render('@ai/settings/edit.html.twig', array_merge([
            'pageTitle' => $this->translator->trans('ai.settings.title'),
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
            'providers' => $this->providers->allLlm(),
            'keys' => [
                'openai' => trim((string) $this->settings->get('ai.openai_api_key', '')) !== '',
                'anthropic' => trim((string) $this->settings->get('ai.anthropic_api_key', '')) !== '',
                'gemini' => trim((string) $this->settings->get('ai.gemini_api_key', '')) !== '',
                'groq' => trim((string) $this->settings->get('ai.groq_api_key', '')) !== '',
                'openrouter' => trim((string) $this->settings->get('ai.openrouter_api_key', '')) !== '',
                'cerebras' => trim((string) $this->settings->get('ai.cerebras_api_key', '')) !== '',
                'zai' => trim((string) $this->settings->get('ai.zai_api_key', '')) !== '',
                'deepseek' => trim((string) $this->settings->get('ai.deepseek_api_key', '')) !== '',
            ],
            'ollama_base_url' => (string) $this->settings->get('ai.ollama_base_url', 'http://127.0.0.1:11434'),
            'field' => static fn (string $key): string => str_replace('.', '__', $key),
        ], $extra));
    }
}
