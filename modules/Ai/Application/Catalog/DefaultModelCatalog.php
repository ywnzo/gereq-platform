<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Domain\AiModel;
use Modules\Ai\Domain\AiProvider;

/**
 * Seed providers + models. Free-tier entries follow OuterSpacee/free-ai-apis
 * (OpenRouter :free, Groq, Gemini, Cerebras, Ollama…).
 */
final class DefaultModelCatalog
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function seed(): void
    {
        // Fast path: already seeded in this worker / DB.
        static $done = false;
        if ($done) {
            return;
        }

        $modelCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(AiModel::class, 'm')
            ->getQuery()
            ->getSingleScalarResult();

        if ($modelCount >= 40) {
            $obsolete = (int) $this->em->createQueryBuilder()
                ->select('COUNT(m.id)')
                ->from(AiModel::class, 'm')
                ->where('m.modelId IN (:ids)')
                ->setParameter('ids', [
                    'gemini-2.0-flash',
                    'gemini-2.5-flash',
                    'text-embedding-004',
                    'llama-3.1-8b-instant',
                    'llama-3.3-70b-versatile',
                ])
                ->getQuery()
                ->getSingleScalarResult();
            if ($obsolete === 0 && $this->em->getRepository(AiProvider::class)->findOneBy(['driver' => 'cerebras'])) {
                $done = true;
                return;
            }
        }

        $providers = [
            ['ollama', 'Ollama (local)', 'http://127.0.0.1:11434', 'ai.ollama_base_url', 5],
            ['openai_compat', 'OpenRouter', 'https://openrouter.ai/api/v1', 'ai.openrouter_api_key', 10],
            ['groq', 'Groq', 'https://api.groq.com/openai/v1', 'ai.groq_api_key', 15],
            ['cerebras', 'Cerebras', 'https://api.cerebras.ai/v1', 'ai.cerebras_api_key', 18],
            ['gemini', 'Google Gemini', 'https://generativelanguage.googleapis.com', 'ai.gemini_api_key', 20],
            ['zai', 'Z.ai (GLM)', 'https://api.z.ai/api/paas/v4', 'ai.zai_api_key', 25],
            ['deepseek', 'DeepSeek', 'https://api.deepseek.com', 'ai.deepseek_api_key', 30],
            ['openai', 'OpenAI', 'https://api.openai.com/v1', 'ai.openai_api_key', 40],
            ['anthropic', 'Anthropic', 'https://api.anthropic.com', 'ai.anthropic_api_key', 50],
        ];

        $byDriver = [];
        foreach ($providers as [$driver, $name, $base, $keySetting, $prio]) {
            $existing = $this->em->getRepository(AiProvider::class)->findOneBy(['driver' => $driver]);
            if ($existing instanceof AiProvider) {
                $byDriver[$driver] = $existing;
                continue;
            }
            $p = new AiProvider($driver, $name, $base, $keySetting, $prio);
            $this->em->persist($p);
            $byDriver[$driver] = $p;
        }
        $this->em->flush();

        // Rename retired provider model IDs before inserting current catalog rows.
        $this->renameObsoleteModels([
            'gemini-2.0-flash' => 'gemini-3.6-flash',
            'gemini-2.5-flash' => 'gemini-3.6-flash',
            'gemini-2.5-pro' => 'gemini-3.1-pro-preview',
            'gemini-1.5-flash' => 'gemini-3.6-flash',
            'gemini-1.5-pro' => 'gemini-3.1-pro-preview',
            'text-embedding-004' => 'gemini-embedding-001',
            'embedding-001' => 'gemini-embedding-001',
            // Groq: Llama moved to Enterprise; free/dev → GPT-OSS / Qwen
            'llama-3.1-8b-instant' => 'openai/gpt-oss-20b',
            'llama-3.3-70b-versatile' => 'openai/gpt-oss-120b',
            'qwen/qwen3-32b' => 'qwen/qwen3.6-27b',
            'meta-llama/llama-4-scout-17b-16e-instruct' => 'openai/gpt-oss-20b',
        ]);

        $models = [
            // —— Ollama (local, unlimited) ——
            ['ollama', 'llama3.2', 'chat', 'llama', ['supports_tools' => true, 'is_free_tier' => true, 'is_open_weights' => true]],
            ['ollama', 'qwen3:8b', 'fast', 'qwen', ['supports_tools' => true, 'supports_thinking' => true, 'is_free_tier' => true, 'is_open_weights' => true]],
            ['ollama', 'qwen3:32b', 'chat', 'qwen', ['supports_tools' => true, 'supports_thinking' => true, 'is_free_tier' => true, 'is_open_weights' => true]],
            ['ollama', 'nomic-embed-text', 'embed', 'other', ['embedding_dimensions' => 768, 'is_free_tier' => true, 'is_open_weights' => true]],
            ['ollama', 'qwen3-embedding', 'embed', 'qwen', ['embedding_dimensions' => 1024, 'is_free_tier' => true, 'is_open_weights' => true]],

            // —— OpenRouter free router + :free models (live snapshot) ——
            ['openai_compat', 'openrouter/free', 'fast', 'other', ['is_free_tier' => true, 'supports_tools' => true]],
            ['openai_compat', 'google/gemma-4-31b-it:free', 'chat', 'other', ['is_free_tier' => true, 'is_open_weights' => true]],
            ['openai_compat', 'google/gemma-4-26b-a4b-it:free', 'fast', 'other', ['is_free_tier' => true, 'is_open_weights' => true]],
            ['openai_compat', 'nvidia/nemotron-3.5-lightning:free', 'fast', 'other', ['is_free_tier' => true, 'is_open_weights' => true]],
            ['openai_compat', 'nvidia/nemotron-3-super-120b-a12b:free', 'chat', 'other', ['is_free_tier' => true, 'is_open_weights' => true, 'supports_thinking' => true]],
            ['openai_compat', 'nvidia/nemotron-3-ultra-550b-a55b:free', 'heavy', 'other', ['is_free_tier' => true, 'is_open_weights' => true, 'supports_thinking' => true]],
            ['openai_compat', 'nvidia/nemotron-3-nano-omni-30b-a3b-reasoning:free', 'fast', 'other', ['is_free_tier' => true, 'supports_thinking' => true, 'is_open_weights' => true]],
            ['openai_compat', 'poolside/laguna-s-2.1:free', 'chat', 'other', ['is_free_tier' => true]],
            ['openai_compat', 'poolside/laguna-xs-2.1:free', 'fast', 'other', ['is_free_tier' => true]],
            ['openai_compat', 'thinkingmachines/inkling:free', 'chat', 'other', ['is_free_tier' => true]],
            ['openai_compat', 'thinkingmachines/inkling-small:free', 'fast', 'other', ['is_free_tier' => true]],
            ['openai_compat', 'cohere/north-mini-code:free', 'fast', 'other', ['is_free_tier' => true]],
            ['openai_compat', 'inclusionai/ling-3.0-flash-fin:free', 'fast', 'other', ['is_free_tier' => true]],
            ['openai_compat', 'liquid/lfm-2.5-2.6b:free', 'fast', 'other', ['is_free_tier' => true, 'is_open_weights' => true]],
            // Paid OpenRouter (kept for when key has credits)
            ['openai_compat', 'z-ai/glm-4.7', 'chat', 'glm', ['supports_tools' => true]],
            ['openai_compat', 'z-ai/glm-5.2', 'heavy', 'glm', ['supports_tools' => true, 'supports_thinking' => true]],
            ['openai_compat', 'qwen/qwen3-235b-a22b', 'chat', 'qwen', ['supports_tools' => true, 'supports_thinking' => true, 'is_open_weights' => true]],
            ['openai_compat', 'deepseek/deepseek-chat', 'chat', 'deepseek', ['supports_tools' => true]],
            ['openai_compat', 'deepseek/deepseek-r1', 'heavy', 'deepseek', ['supports_thinking' => true]],

            // —— Groq (developer free: GPT-OSS / Qwen; Llama = Enterprise only) ——
            ['groq', 'openai/gpt-oss-120b', 'chat', 'openai', ['supports_tools' => true, 'supports_thinking' => true, 'is_free_tier' => true, 'is_open_weights' => true]],
            ['groq', 'openai/gpt-oss-20b', 'fast', 'openai', ['supports_tools' => true, 'supports_thinking' => true, 'is_free_tier' => true, 'is_open_weights' => true]],
            ['groq', 'qwen/qwen3.6-27b', 'chat', 'qwen', ['supports_tools' => true, 'supports_vision' => true, 'supports_thinking' => true, 'is_free_tier' => true, 'is_open_weights' => true]],
            ['groq', 'qwen/qwen3.8-27b', 'heavy', 'qwen', ['supports_tools' => true, 'supports_vision' => true, 'supports_thinking' => true, 'is_free_tier' => true, 'is_open_weights' => true]],

            // —— Cerebras free tier ——
            ['cerebras', 'llama-3.3-70b', 'chat', 'llama', ['supports_tools' => true, 'is_free_tier' => true, 'is_open_weights' => true]],
            ['cerebras', 'llama3.1-8b', 'fast', 'llama', ['is_free_tier' => true, 'is_open_weights' => true]],
            ['cerebras', 'qwen-3-32b', 'chat', 'qwen', ['supports_tools' => true, 'is_free_tier' => true, 'is_open_weights' => true]],

            // —— Gemini AI Studio free tier ——
            ['gemini', 'gemini-3.6-flash', 'fast', 'gemini', ['supports_tools' => true, 'supports_vision' => true, 'is_free_tier' => true]],
            ['gemini', 'gemini-3.5-flash', 'fast', 'gemini', ['supports_tools' => true, 'supports_vision' => true, 'is_free_tier' => true]],
            ['gemini', 'gemini-3.1-pro-preview', 'heavy', 'gemini', ['supports_tools' => true, 'supports_vision' => true, 'supports_thinking' => true, 'is_free_tier' => true]],
            ['gemini', 'gemini-embedding-001', 'embed', 'gemini', ['embedding_dimensions' => 3072, 'is_free_tier' => true]],
            ['gemini', 'gemini-embedding-2', 'embed', 'gemini', ['embedding_dimensions' => 3072, 'is_free_tier' => true]],

            // —— Z.ai GLM ——
            ['zai', 'glm-3-turbo', 'fast', 'glm', ['supports_tools' => true, 'is_free_tier' => false]],
            ['zai', 'glm-4-flash', 'fast', 'glm', ['supports_tools' => true, 'is_free_tier' => true]],
            ['zai', 'glm-4.6', 'chat', 'glm', ['supports_tools' => true]],
            ['zai', 'glm-4.7', 'chat', 'glm', ['supports_tools' => true]],
            ['zai', 'glm-5.2', 'heavy', 'glm', ['supports_tools' => true, 'supports_thinking' => true, 'metadata' => ['reasoning_effort' => 'high']]],
            ['zai', 'glm-5.3', 'heavy', 'glm', ['supports_tools' => true, 'supports_thinking' => true, 'is_open_weights' => true, 'metadata' => ['reasoning_effort' => 'max']]],
            ['zai', 'glm-5.3-flash', 'fast', 'glm', ['supports_tools' => true, 'supports_thinking' => true, 'is_free_tier' => true]],

            // —— DeepSeek ——
            ['deepseek', 'deepseek-chat', 'chat', 'deepseek', ['supports_tools' => true, 'is_open_weights' => true]],
            ['deepseek', 'deepseek-reasoner', 'heavy', 'deepseek', ['supports_thinking' => true, 'is_open_weights' => true]],

            // —— OpenAI / Anthropic (paid) ——
            ['openai', 'gpt-4.1', 'chat', 'openai', ['supports_tools' => true, 'supports_vision' => true]],
            ['openai', 'gpt-4.1-mini', 'fast', 'openai', ['supports_tools' => true]],
            ['openai', 'text-embedding-3-small', 'embed', 'openai', ['embedding_dimensions' => 1536]],
            ['anthropic', 'claude-sonnet-4-5', 'chat', 'anthropic', ['supports_tools' => true]],
            ['anthropic', 'claude-haiku-4-5-20251001', 'fast', 'anthropic', ['supports_tools' => true]],
        ];

        foreach ($models as [$driver, $modelId, $role, $family, $opts]) {
            $provider = $byDriver[$driver] ?? null;
            if (!$provider instanceof AiProvider) {
                continue;
            }
            $existing = $this->em->getRepository(AiModel::class)->findOneBy([
                'providerId' => $provider->id(),
                'modelId' => $modelId,
                'role' => $role,
            ]);
            if ($existing instanceof AiModel) {
                continue;
            }
            $m = new AiModel($provider->id(), $modelId, $role, $family, $opts['metadata'] ?? []);
            $m->setSupportsTools((bool) ($opts['supports_tools'] ?? false));
            $m->setSupportsVision((bool) ($opts['supports_vision'] ?? false));
            $m->setSupportsThinking((bool) ($opts['supports_thinking'] ?? false));
            $m->setIsFreeTier((bool) ($opts['is_free_tier'] ?? false));
            $m->setIsOpenWeights((bool) ($opts['is_open_weights'] ?? false));
            if (isset($opts['embedding_dimensions'])) {
                $m->setEmbeddingDimensions((int) $opts['embedding_dimensions']);
            }
            $this->em->persist($m);
        }
        $this->em->flush();
        $this->dedupeModels();
        $done = true;
    }

    /**
     * @param array<string, string> $map old model_id => new model_id
     */
    private function renameObsoleteModels(array $map): void
    {
        foreach ($map as $from => $to) {
            $rows = $this->em->getRepository(AiModel::class)->findBy(['modelId' => $from]);
            foreach ($rows as $row) {
                if (!$row instanceof AiModel) {
                    continue;
                }
                $row->setModelId($to);
                $row->setEnabled(true);
            }
        }
        $this->em->flush();
    }

    private function dedupeModels(): void
    {
        $all = $this->em->getRepository(AiModel::class)->findAll();
        $seen = [];
        foreach ($all as $m) {
            if (!$m instanceof AiModel) {
                continue;
            }
            $key = $m->providerId() . '|' . $m->modelId() . '|' . $m->role();
            if (isset($seen[$key])) {
                $m->setEnabled(false);
                continue;
            }
            $seen[$key] = true;
            $m->setEnabled(true);
        }
        $this->em->flush();
    }
}
