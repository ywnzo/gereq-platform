<?php

declare(strict_types=1);

namespace Modules\Ai;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Queue\Queue;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Ai\Application\AiFacade;
use Modules\Ai\Application\Catalog\DefaultModelCatalog;
use Modules\Ai\Application\Jobs\IngestSourceJob;
use Modules\Ai\Application\LlmProviderRegistry;
use Modules\Ai\Application\Providers\AnthropicLlmProvider;
use Modules\Ai\Application\Providers\GeminiLlmProvider;
use Modules\Ai\Application\Providers\OpenAiCompatLlmProvider;
use Modules\Ai\Application\CustomToolCatalog;
use Modules\Ai\Application\AgentDelegationService;
use Modules\Ai\Application\ToolInvocationContext;
use Modules\Ai\Application\ToolRegistry;
use Modules\Ai\Application\Tools\AgentsAskTool;
use Modules\Ai\Application\Tools\AgentsListTool;
use Modules\Ai\Application\Tools\CrmGetDealTool;
use Modules\Ai\Application\Tools\CrmListActivitiesTool;
use Modules\Ai\Application\Tools\CustomersGetTool;
use Modules\Ai\Application\Tools\CustomersSearchTool;
use Modules\Ai\Application\Tools\DocumentsReadTextTool;
use Modules\Ai\Application\Tools\DocumentsSearchTool;
use Modules\Ai\Application\Tools\ListingsGetTool;
use Modules\Ai\Application\Tools\ListingsSearchTool;
use Modules\Ai\Domain\AiKnowledgeBase;
use Modules\Ai\Domain\AiSource;
use Modules\Ai\Http\AgentController;
use Modules\Ai\Http\AiChatController;
use Modules\Ai\Http\Api\AiAgentApiController;
use Modules\Ai\Http\CustomToolController;
use Modules\Ai\Http\KnowledgeController;
use Modules\Ai\Http\SettingsController;

final class AiModule implements ModuleInterface
{
    public function id(): string
    {
        return 'ai';
    }

    public function version(): string
    {
        return '1.2.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('ai.use', 'perm.ai.use', 'perm.group.ai');
        $context->permissions()->register('ai.manage', 'perm.ai.manage', 'perm.group.ai');

        $this->defineSettings($context);
        $this->registerBuiltinProviders($context);

        $facade = $context->get(AiFacade::class);
        $context->capabilities()->register('ai', $this->id(), $facade);

        $this->registerTools($context);

        $context->router()->group('/admin/ai', function (Router $routes): void {
            $routes->get('/settings', [SettingsController::class, 'edit'])->name('admin.ai.settings')->permission('ai.manage');
            $routes->post('/settings', [SettingsController::class, 'update'])->name('admin.ai.settings.update')->permission('ai.manage');
            $routes->post('/settings/ping', [SettingsController::class, 'ping'])->name('admin.ai.settings.ping')->permission('ai.manage');
            $routes->post('/settings/seed-catalog', [SettingsController::class, 'seedCatalog'])->name('admin.ai.settings.seed')->permission('ai.manage');

            $routes->get('/agents', [AgentController::class, 'index'])->name('admin.ai.agents.index')->permission('ai.use');
            $routes->get('/agents/create', [AgentController::class, 'create'])->name('admin.ai.agents.create')->permission('ai.manage');
            $routes->post('/agents', [AgentController::class, 'store'])->name('admin.ai.agents.store')->permission('ai.manage');
            $routes->get('/agents/{id}', [AgentController::class, 'edit'])->name('admin.ai.agents.edit')->permission('ai.manage');
            $routes->post('/agents/{id}', [AgentController::class, 'update'])->name('admin.ai.agents.update')->permission('ai.manage');
            $routes->post('/agents/{id}/delete', [AgentController::class, 'destroy'])->name('admin.ai.agents.destroy')->permission('ai.manage');

            $routes->get('/knowledge', [KnowledgeController::class, 'index'])->name('admin.ai.knowledge.index')->permission('ai.manage');
            $routes->post('/knowledge', [KnowledgeController::class, 'store'])->name('admin.ai.knowledge.store')->permission('ai.manage');
            $routes->post('/knowledge/{id}/source', [KnowledgeController::class, 'addSource'])->name('admin.ai.knowledge.source')->permission('ai.manage');
            $routes->post('/knowledge/{id}/reindex', [KnowledgeController::class, 'reindex'])->name('admin.ai.knowledge.reindex')->permission('ai.manage');
            $routes->get('/knowledge/sources/{id}', [KnowledgeController::class, 'editSource'])->name('admin.ai.knowledge.source.edit')->permission('ai.manage');
            $routes->post('/knowledge/sources/{id}', [KnowledgeController::class, 'updateSource'])->name('admin.ai.knowledge.source.update')->permission('ai.manage');
            $routes->post('/knowledge/sources/{id}/reingest', [KnowledgeController::class, 'reingestSource'])->name('admin.ai.knowledge.source.reingest')->permission('ai.manage');
            $routes->post('/knowledge/sources/{id}/delete', [KnowledgeController::class, 'destroySource'])->name('admin.ai.knowledge.source.destroy')->permission('ai.manage');

            $routes->get('/tools', [CustomToolController::class, 'index'])->name('admin.ai.tools.index')->permission('ai.manage');
            $routes->get('/tools/create', [CustomToolController::class, 'create'])->name('admin.ai.tools.create')->permission('ai.manage');
            $routes->post('/tools', [CustomToolController::class, 'store'])->name('admin.ai.tools.store')->permission('ai.manage');
            $routes->get('/tools/{id}', [CustomToolController::class, 'edit'])->name('admin.ai.tools.edit')->permission('ai.manage');
            $routes->post('/tools/{id}', [CustomToolController::class, 'update'])->name('admin.ai.tools.update')->permission('ai.manage');
            $routes->post('/tools/{id}/delete', [CustomToolController::class, 'destroy'])->name('admin.ai.tools.destroy')->permission('ai.manage');

            $routes->get('/chat/{id}', [AiChatController::class, 'show'])->name('admin.ai.chat')->permission('ai.use');
            $routes->post('/chat/{id}', [AiChatController::class, 'send'])->name('admin.ai.chat.send')->permission('ai.use');
            $routes->post('/chat/{id}/stream', [AiChatController::class, 'stream'])->name('admin.ai.chat.stream')->permission('ai.use');
            $routes->post('/chat/{id}/feedback', [AiChatController::class, 'feedback'])->name('admin.ai.chat.feedback')->permission('ai.use');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('ai.admin');
        $context->api()->registerKnownScope('ai.agent.*');
        $context->api()->module('ai', function (ApiModule $api): void {
            // Scope checked in controller against ai.agent.{slug} / ai.admin (FE isolation).
            $api->post('/agents/{slug}/chat', [AiAgentApiController::class, 'chat']);
        });

        $v = $context->validation();
        $v->ruleset('ai.agent.save', [
            'slug' => 'required|min:2|max:80',
            'name' => 'required|min:2|max:190',
            'system_prompt' => 'required|min:3',
            'write_mode' => 'nullable|in:none,draft,confirm',
            'published' => 'nullable',
            'temperature' => 'nullable',
        ]);
        $v->ruleset('ai.knowledge.save', [
            'slug' => 'required|min:2|max:80',
            'name' => 'required|min:2|max:190',
            'visibility' => 'required|in:internal,public',
        ]);
        $v->ruleset('ai.custom_tool.save', [
            'name' => 'required|min:3|max:120',
            'label' => 'required|min:2|max:255',
            'description' => 'required|min:3',
            'handler_type' => 'required|in:http,static',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'ai',
            label: 'nav.ai',
            icon: 'zap',
            route: 'admin.ai.agents.index',
            permission: 'ai.use',
            order: 72,
            group: 'Integrace',
        ));
        $context->menu()->add(new MenuItem(
            id: 'ai.agents',
            label: 'nav.ai.agents',
            route: 'admin.ai.agents.index',
            permission: 'ai.use',
            parent: 'ai',
            order: 73,
        ));
        $context->menu()->add(new MenuItem(
            id: 'ai.knowledge',
            label: 'nav.ai.knowledge',
            route: 'admin.ai.knowledge.index',
            permission: 'ai.manage',
            parent: 'ai',
            order: 74,
        ));
        $context->menu()->add(new MenuItem(
            id: 'ai.tools',
            label: 'nav.ai.tools',
            route: 'admin.ai.tools.index',
            permission: 'ai.manage',
            parent: 'ai',
            order: 75,
        ));
        $context->menu()->add(new MenuItem(
            id: 'ai.settings',
            label: 'nav.ai.settings',
            route: 'admin.ai.settings',
            permission: 'ai.manage',
            parent: 'ai',
            order: 76,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'ai.agents',
            label: 'palette.ai.agents',
            url: '/admin/ai/agents',
            icon: 'zap',
            permission: 'ai.use',
            keywords: ['ai', 'agent', 'llm', 'rag', 'glm'],
        ));

        try {
            $context->get(DefaultModelCatalog::class)->seed();
        } catch (\Throwable) {
            // Tables may not exist until module update / migrate.
        }

        $this->registerDocumentHook($context);
    }

    private function defineSettings(ModuleContext $context): void
    {
        $defs = [
            ['ai.openai_api_key', SettingRecord::TYPE_ENCRYPTED, 'ai.settings.openai_api_key', ''],
            ['ai.anthropic_api_key', SettingRecord::TYPE_ENCRYPTED, 'ai.settings.anthropic_api_key', ''],
            ['ai.gemini_api_key', SettingRecord::TYPE_ENCRYPTED, 'ai.settings.gemini_api_key', ''],
            ['ai.groq_api_key', SettingRecord::TYPE_ENCRYPTED, 'ai.settings.groq_api_key', ''],
            ['ai.openrouter_api_key', SettingRecord::TYPE_ENCRYPTED, 'ai.settings.openrouter_api_key', ''],
            ['ai.cerebras_api_key', SettingRecord::TYPE_ENCRYPTED, 'ai.settings.cerebras_api_key', ''],
            ['ai.zai_api_key', SettingRecord::TYPE_ENCRYPTED, 'ai.settings.zai_api_key', ''],
            ['ai.deepseek_api_key', SettingRecord::TYPE_ENCRYPTED, 'ai.settings.deepseek_api_key', ''],
            ['ai.ollama_base_url', SettingRecord::TYPE_STRING, 'ai.settings.ollama_base_url', 'http://127.0.0.1:11434'],
        ];
        foreach ($defs as [$key, $type, $label, $default]) {
            $context->settings()->define($key, $type, $label, $default, $this->id(), [], false);
        }
    }

    private function registerBuiltinProviders(ModuleContext $context): void
    {
        /** @var LlmProviderRegistry $registry */
        $registry = $context->get(LlmProviderRegistry::class);
        /** @var Settings $settings */
        $settings = $context->get(Settings::class);

        $ollama = new OpenAiCompatLlmProvider('ollama', $settings, '', 'http://127.0.0.1:11434', 'ai.ollama_base_url');
        $registry->registerLlm($ollama);
        $registry->registerEmbedding($ollama);

        $openrouter = new OpenAiCompatLlmProvider('openai_compat', $settings, 'ai.openrouter_api_key', 'https://openrouter.ai/api/v1');
        $registry->registerLlm($openrouter);

        $openai = new OpenAiCompatLlmProvider('openai', $settings, 'ai.openai_api_key', 'https://api.openai.com/v1');
        $registry->registerLlm($openai);
        $registry->registerEmbedding($openai);

        $groq = new OpenAiCompatLlmProvider('groq', $settings, 'ai.groq_api_key', 'https://api.groq.com/openai/v1');
        $registry->registerLlm($groq);

        $cerebras = new OpenAiCompatLlmProvider('cerebras', $settings, 'ai.cerebras_api_key', 'https://api.cerebras.ai/v1');
        $registry->registerLlm($cerebras);

        $zai = new OpenAiCompatLlmProvider('zai', $settings, 'ai.zai_api_key', 'https://api.z.ai/api/paas/v4');
        $registry->registerLlm($zai);

        $deepseek = new OpenAiCompatLlmProvider('deepseek', $settings, 'ai.deepseek_api_key', 'https://api.deepseek.com');
        $registry->registerLlm($deepseek);

        $anthropic = new AnthropicLlmProvider($settings);
        $registry->registerLlm($anthropic);

        $gemini = new GeminiLlmProvider($settings);
        $registry->registerLlm($gemini);
        $registry->registerEmbedding($gemini);
    }

    private function registerTools(ModuleContext $context): void
    {
        /** @var ToolRegistry $tools */
        $tools = $context->get(ToolRegistry::class);
        $em = $context->get(EntityManagerInterface::class);

        $tools->register(new CustomersSearchTool($em));
        $tools->register(new CustomersGetTool($em));
        $tools->register(new CrmGetDealTool($em));
        $tools->register(new CrmListActivitiesTool($em));
        $tools->register(new ListingsGetTool($em));
        $tools->register(new ListingsSearchTool($em));
        $tools->register(new DocumentsSearchTool($em));
        $tools->register(new DocumentsReadTextTool($em));

        $delegation = $context->get(AgentDelegationService::class);
        $toolCtx = $context->get(ToolInvocationContext::class);
        $tools->register(new AgentsListTool($delegation, $toolCtx));
        $tools->register(new AgentsAskTool($delegation, $toolCtx));
        $tools->setCustomCatalog($context->get(CustomToolCatalog::class));
    }

    private function registerDocumentHook(ModuleContext $context): void
    {
        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (EventEnvelope $envelope) use ($context): void {
                if ($envelope->type !== 'document.uploaded') {
                    return;
                }
                $docId = (string) ($envelope->resource['id'] ?? '');
                if ($docId === '') {
                    return;
                }
                try {
                    $em = $context->get(EntityManagerInterface::class);
                    $kb = $em->getRepository(AiKnowledgeBase::class)->findOneBy(['slug' => 'documents-internal']);
                    if (!$kb instanceof AiKnowledgeBase) {
                        $kb = new AiKnowledgeBase('documents-internal', 'Documents (internal)', 'internal');
                        $em->persist($kb);
                        $em->flush();
                    }
                    $existing = $em->getRepository(AiSource::class)->findOneBy([
                        'knowledgeBaseId' => $kb->id(),
                        'sourceType' => 'document',
                        'sourceRef' => $docId,
                    ]);
                    if ($existing instanceof AiSource) {
                        $existing->markStale();
                        $existing->markPending();
                        $source = $existing;
                    } else {
                        $title = (string) ($envelope->data['title'] ?? ('Document ' . $docId));
                        $source = new AiSource($kb->id(), 'document', $docId, $title);
                        $em->persist($source);
                    }
                    $em->flush();
                    $context->get(Queue::class)->dispatch(new IngestSourceJob($source->id()));
                } catch (\Throwable) {
                }
            },
            HookPriority::LATE,
        );
    }
}
