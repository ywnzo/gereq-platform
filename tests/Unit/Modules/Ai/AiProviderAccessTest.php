<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ai;

use Modules\Ai\Application\AiIntegrationGate;
use Modules\Ai\Application\AiProviderAccess;
use Modules\Ai\Application\Contracts\LlmProvider;
use Modules\Ai\Application\LlmProviderRegistry;
use Modules\Ai\Domain\AiModel;
use Modules\Ai\Domain\AiProvider;
use PHPUnit\Framework\TestCase;

final class AiProviderAccessTest extends TestCase
{
    public function testGeminiBlockedWhenIntegrationDisabled(): void
    {
        $gate = new FakeAiIntegrationGate(['gemini' => false]);
        $gemini = $this->createMock(LlmProvider::class);
        $gemini->method('driver')->willReturn('gemini');
        $gemini->method('isConfigured')->willReturn(true);

        $registry = new LlmProviderRegistry();
        $registry->registerLlm($gemini);

        $access = new AiProviderAccess($gate, $registry);

        self::assertFalse($access->isDriverAllowed('gemini'));
        self::assertFalse($access->isDriverUsable('gemini'));
        self::assertTrue($access->isDriverAllowed('groq'));
    }

    public function testModelSelectOptionsSkipDisabledIntegration(): void
    {
        $gate = new FakeAiIntegrationGate(['gemini' => true, 'openai_compat' => false]);

        $geminiLlm = $this->createMock(LlmProvider::class);
        $geminiLlm->method('driver')->willReturn('gemini');
        $geminiLlm->method('isConfigured')->willReturn(true);

        $orLlm = $this->createMock(LlmProvider::class);
        $orLlm->method('driver')->willReturn('openai_compat');
        $orLlm->method('isConfigured')->willReturn(true);

        $registry = new LlmProviderRegistry();
        $registry->registerLlm($geminiLlm);
        $registry->registerLlm($orLlm);

        $access = new AiProviderAccess($gate, $registry);

        $geminiProvider = new AiProvider('gemini', 'Gemini', null, 'ai.gemini_api_key', 20);
        $orProvider = new AiProvider('openai_compat', 'OpenRouter', null, 'ai.openrouter_api_key', 10);

        $geminiModel = new AiModel($geminiProvider->id(), 'gemini-3.6-flash', 'chat', 'gemini');
        $orModel = new AiModel($orProvider->id(), 'openrouter/free', 'fast', 'other');

        $providers = [
            $geminiProvider->id() => $geminiProvider,
            $orProvider->id() => $orProvider,
        ];

        $options = $access->modelSelectOptions([$geminiModel, $orModel], $providers, 'chat');

        self::assertCount(1, $options);
        self::assertSame($geminiModel->id(), $options[0]['value']);
    }
}

/** @param array<string, bool> $enabled */
final class FakeAiIntegrationGate implements AiIntegrationGate
{
    public function __construct(private readonly array $enabled)
    {
    }

    public function isEnabled(string $integrationModuleId): bool
    {
        return $this->enabled[$integrationModuleId] ?? false;
    }

    public function moduleIcon(string $integrationModuleId): string
    {
        return 'zap';
    }
}
