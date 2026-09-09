<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Contracts\EmbeddingProvider;
use Modules\Ai\Application\Contracts\LlmProvider;
use Modules\Ai\Application\Dto\ChatRequest;
use Modules\Ai\Application\Dto\ChatResponse;
use Modules\Ai\Domain\AiModel;
use Modules\Ai\Domain\AiProvider;

final class ModelRouter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LlmProviderRegistry $registry,
    ) {
    }

    public function resolveLlm(?string $modelEntityId, string $preferredRole = 'chat'): array
    {
        $model = $this->resolveModel($modelEntityId, $preferredRole);
        $provider = $this->providerEntity($model->providerId());
        $llm = $this->registry->llm($provider->driver());
        if ($llm === null || !$llm->isConfigured()) {
            throw new \RuntimeException(sprintf('LLM driver "%s" není dostupný nebo nakonfigurovaný.', $provider->driver()));
        }

        return ['model' => $model, 'provider' => $provider, 'llm' => $llm];
    }

    public function resolveEmbedding(?string $modelEntityId): array
    {
        $model = $this->resolveModel($modelEntityId, 'embed');
        $provider = $this->providerEntity($model->providerId());
        $emb = $this->registry->embedding($provider->driver());
        if ($emb === null || !$emb->isConfigured()) {
            throw new \RuntimeException(sprintf('Embedding driver "%s" není dostupný.', $provider->driver()));
        }

        return ['model' => $model, 'provider' => $provider, 'embedding' => $emb];
    }

    public function chatWithModel(AiModel $model, ChatRequest $request): ChatResponse
    {
        $provider = $this->providerEntity($model->providerId());
        $llm = $this->registry->llm($provider->driver());
        if ($llm === null) {
            throw new \RuntimeException('Missing LLM driver: ' . $provider->driver());
        }
        $req = $this->prepareRequest($model, $provider, $request);

        return $llm->chat($req);
    }

    /**
     * @param callable(string $delta): void $onDelta
     */
    public function chatStreamWithModel(AiModel $model, ChatRequest $request, callable $onDelta): ChatResponse
    {
        $provider = $this->providerEntity($model->providerId());
        $llm = $this->registry->llm($provider->driver());
        if ($llm === null) {
            throw new \RuntimeException('Missing LLM driver: ' . $provider->driver());
        }
        $req = $this->prepareRequest($model, $provider, $request);

        if ($llm instanceof \Modules\Ai\Application\Contracts\StreamableLlm) {
            return $llm->chatStream($req, $onDelta);
        }

        $response = $llm->chat($req);
        if (!$response->hasToolCalls()) {
            $parts = preg_split('/(?<=\s)/u', $response->content) ?: [$response->content];
            foreach ($parts as $part) {
                if ($part !== '') {
                    $onDelta($part);
                }
            }
        }

        return $response;
    }

    private function prepareRequest(AiModel $model, AiProvider $provider, ChatRequest $request): ChatRequest
    {
        $extra = $request->extra;
        if ($model->supportsThinking() && $provider->driver() === 'zai') {
            $effort = (string) ($model->metadata()['reasoning_effort'] ?? 'low');
            $extra['thinking'] = ['type' => 'enabled'];
            $extra['reasoning_effort'] = $effort;
        }

        return new ChatRequest(
            model: $model->modelId(),
            messages: $request->messages,
            temperature: $request->temperature,
            tools: $request->tools,
            extra: $extra,
        );
    }

    private function resolveModel(?string $modelEntityId, string $role): AiModel
    {
        if ($modelEntityId !== null && $modelEntityId !== '') {
            $model = $this->em->find(AiModel::class, $modelEntityId);
            if ($model instanceof AiModel && $model->enabled()) {
                return $model;
            }
        }

        $candidates = $this->em->getRepository(AiModel::class)->createQueryBuilder('m')
            ->andWhere('m.enabled = true')
            ->andWhere('m.role = :role')
            ->setParameter('role', $role)
            ->orderBy('m.isFreeTier', 'DESC')
            ->addOrderBy('m.modelId', 'ASC')
            ->getQuery()
            ->getResult();

        $model = $this->firstConfigured($candidates);
        if (!$model instanceof AiModel) {
            $fallback = $this->em->getRepository(AiModel::class)->createQueryBuilder('m')
                ->andWhere('m.enabled = true')
                ->orderBy('m.isFreeTier', 'DESC')
                ->getQuery()
                ->getResult();
            $model = $this->firstConfigured($fallback);
        }
        if (!$model instanceof AiModel) {
            throw new \RuntimeException('Žádný AI model v katalogu s nakonfigurovaným providerem. Doplňte API klíč (OpenRouter / Groq / Gemini / Cerebras) nebo spusťte Ollama.');
        }

        return $model;
    }

    /**
     * @param list<AiModel> $candidates
     */
    private function firstConfigured(array $candidates): ?AiModel
    {
        foreach ($candidates as $model) {
            if (!$model instanceof AiModel) {
                continue;
            }
            try {
                $provider = $this->providerEntity($model->providerId());
            } catch (\RuntimeException) {
                continue;
            }
            $driver = $provider->driver();
            $llm = $this->registry->llm($driver);
            $emb = $this->registry->embedding($driver);
            $ok = ($llm !== null && $llm->isConfigured())
                || ($emb !== null && $emb->isConfigured());
            if ($ok) {
                return $model;
            }
        }

        return null;
    }

    private function providerEntity(string $id): AiProvider
    {
        $p = $this->em->find(AiProvider::class, $id);
        if (!$p instanceof AiProvider || !$p->enabled()) {
            throw new \RuntimeException('AI provider není dostupný.');
        }

        return $p;
    }
}
