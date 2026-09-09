<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\ChatMessage;
use Modules\Ai\Application\Dto\ChatRequest;
use Modules\Ai\Application\Rag\RagRetriever;
use Modules\Ai\Domain\AiAgent;
use Modules\Ai\Domain\AiConversation;
use Modules\Ai\Domain\AiMessage;
use Modules\Ai\Domain\AiToolInvocation;

final class AiOrchestrator
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgentService $agents,
        private readonly ToolRegistry $tools,
        private readonly ModelRouter $router,
        private readonly RagRetriever $rag,
        private readonly UsageTracker $usage,
        private readonly ToolInvocationContext $toolCtx,
        private readonly AgentDelegationService $delegation,
    ) {
    }

    /**
     * @return array{
     *   conversation_id: string,
     *   message: string,
     *   message_id: string|null,
     *   citations: list<array<string, mixed>>,
     *   model: string,
     *   tokens_in: int,
     *   tokens_out: int
     * }
     */
    public function chat(
        AiAgent $agent,
        AiActor $actor,
        string $userMessage,
        ?string $conversationId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        bool $requirePublished = false,
    ): array {
        return $this->run($agent, $actor, $userMessage, $conversationId, $entityType, $entityId, $requirePublished, null);
    }

    /**
     * Stream events via $emit(array $event). Event types: status, tool, delta, done, error.
     *
     * @param callable(array<string, mixed>): void $emit
     * @return array{
     *   conversation_id: string,
     *   message: string,
     *   message_id: string|null,
     *   citations: list<array<string, mixed>>,
     *   model: string,
     *   tokens_in: int,
     *   tokens_out: int
     * }
     */
    public function chatStream(
        AiAgent $agent,
        AiActor $actor,
        string $userMessage,
        callable $emit,
        ?string $conversationId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        bool $requirePublished = false,
    ): array {
        return $this->run($agent, $actor, $userMessage, $conversationId, $entityType, $entityId, $requirePublished, $emit);
    }

    /**
     * @param callable(array<string, mixed>): void|null $emit
     * @return array{
     *   conversation_id: string,
     *   message: string,
     *   message_id: string|null,
     *   citations: list<array<string, mixed>>,
     *   model: string,
     *   tokens_in: int,
     *   tokens_out: int
     * }
     */
    private function run(
        AiAgent $agent,
        AiActor $actor,
        string $userMessage,
        ?string $conversationId,
        ?string $entityType,
        ?string $entityId,
        bool $requirePublished,
        ?callable $emit,
    ): array {
        $this->agents->assertAccessible($agent, $actor, $requirePublished);
        $fire = static function (string $type, array $payload = []) use ($emit): void {
            if ($emit === null) {
                return;
            }
            $emit(array_merge(['type' => $type], $payload));
        };

        $prevCaller = $this->toolCtx->caller();
        $prevConversationId = $this->toolCtx->conversationId();

        $conversation = null;
        if ($conversationId !== null && $conversationId !== '') {
            $conversation = $this->em->find(AiConversation::class, $conversationId);
            if (!$conversation instanceof AiConversation || $conversation->agentId() !== $agent->id()) {
                throw new \RuntimeException('Conversation not found.', 404);
            }
        }
        if ($conversation === null) {
            $conversation = new AiConversation(
                $agent->id(),
                $actor->surface,
                $actor->type,
                $actor->id,
                $entityType,
                $entityId,
            );
            $this->em->persist($conversation);
            $this->em->flush();
        }

        $this->toolCtx->set($agent, $conversation->id());

        try {
            return $this->runInner(
                $agent,
                $actor,
                $userMessage,
                $conversation,
                $entityType,
                $entityId,
                $fire,
                $emit,
            );
        } finally {
            $this->toolCtx->set($prevCaller, $prevConversationId);
        }
    }

    /**
     * @param callable(string, array<string, mixed>): void $fire
     * @param callable(array<string, mixed>): void|null $emit
     * @return array{
     *   conversation_id: string,
     *   message: string,
     *   message_id: string|null,
     *   citations: list<array<string, mixed>>,
     *   model: string,
     *   tokens_in: int,
     *   tokens_out: int
     * }
     */
    private function runInner(
        AiAgent $agent,
        AiActor $actor,
        string $userMessage,
        AiConversation $conversation,
        ?string $entityType,
        ?string $entityId,
        callable $fire,
        ?callable $emit,
    ): array {
        $userMsg = new AiMessage($conversation->id(), 'user', $userMessage);
        $this->em->persist($userMsg);
        $this->em->flush();
        $fire('status', ['message' => 'message_saved']);

        $publicOnly = in_array($actor->surface, ['api', 'public'], true);
        $kbIds = $this->agents->knowledgeBaseIdsForChat($agent->id(), $publicOnly);
        $citations = [];
        $ragBlock = '';
        if ($kbIds !== []) {
            $fire('status', ['message' => 'rag']);
            try {
                $citations = $this->rag->retrieve($userMessage, $kbIds, $agent->embedModelId());
                $ragBlock = $this->rag->formatCitations($citations);
            } catch (\Throwable) {
                $citations = [];
            }
        }

        $system = $agent->systemPrompt();
        if ($agent->playbook()) {
            $system .= "\n\n" . $agent->playbook();
        }
        $system .= "\n\nStyl odpovědi: piš srozumitelně a konkrétně. Používej Markdown"
            . " (## nadpisy, seznamy, **tučné** pro klíčová fakta, `kód` pro ID a názvy tools)."
            . " Výsledky tools shrň lidsky — nevypisuj surový JSON, pokud to uživatel výslovně nechce.";
        $peerBlock = $this->delegation->promptBlock($agent, $actor);
        if ($peerBlock !== '') {
            $system .= "\n\n" . $peerBlock;
        }
        if ($ragBlock !== '') {
            $system .= "\n\n" . $ragBlock;
        }
        if ($entityType && $entityId) {
            $ctx = $this->tools->resolveContext($entityType, $entityId, $actor);
            if ($ctx !== []) {
                $system .= "\n\nKontext entity:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
        }

        $history = $this->em->getRepository(AiMessage::class)->createQueryBuilder('m')
            ->andWhere('m.conversationId = :c')->setParameter('c', $conversation->id())
            ->andWhere('m.role IN (:roles)')->setParameter('roles', ['user', 'assistant', 'tool'])
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults(40)
            ->getQuery()
            ->getResult();

        $messages = [new ChatMessage('system', $system)];
        foreach ($history as $h) {
            if (!$h instanceof AiMessage) {
                continue;
            }
            $messages[] = new ChatMessage($h->role(), $h->content(), $h->toolName(), $h->toolCallId());
        }

        $allow = $this->agents->toolAllowlist($agent->id());
        $availableTools = $this->tools->forActor($actor, $allow === [] ? [] : $allow);
        if ($allow === []) {
            $availableTools = [];
        }
        // Nested delegation: still allow agents.* so peers can collaborate one hop further.
        $openaiTools = [];
        foreach ($availableTools as $tool) {
            $openaiTools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parametersSchema(),
                ],
            ];
        }

        $resolved = $this->router->resolveLlm($agent->chatModelId(), 'chat');
        $model = $resolved['model'];
        $provider = $resolved['provider'];

        $totalIn = 0;
        $totalOut = 0;
        $finalText = '';
        $finalMessageId = null;
        $rounds = max(1, $agent->maxToolRounds());
        $response = null;
        $alreadyStreamed = false;

        for ($round = 0; $round < $rounds; $round++) {
            $fire('status', ['message' => 'thinking', 'round' => $round + 1]);

            $request = new ChatRequest(
                model: $model->modelId(),
                messages: $messages,
                temperature: $agent->temperature(),
                tools: $openaiTools,
            );

            // Real token stream only when the agent has no tools (otherwise tool JSON breaks SSE UX).
            // After tool rounds, stream the final prose.
            if ($emit !== null && $openaiTools === []) {
                $fire('status', ['message' => 'generating', 'round' => $round + 1]);
                $response = $this->router->chatStreamWithModel($model, $request, static function (string $delta) use ($fire): void {
                    $fire('delta', ['text' => $delta]);
                });
                $alreadyStreamed = true;
            } else {
                $response = $this->router->chatWithModel($model, $request);
            }

            $totalIn += $response->tokensIn;
            $totalOut += $response->tokensOut;

            if ($response->hasToolCalls()) {
                $assistant = new ChatMessage('assistant', $response->content, toolCalls: $response->toolCalls);
                $messages[] = $assistant;
                $asstEntity = new AiMessage($conversation->id(), 'assistant', $response->content);
                $asstEntity->setModelId($model->id());
                $asstEntity->setTokens($response->tokensIn, $response->tokensOut);
                $this->em->persist($asstEntity);
                $this->em->flush();

                foreach ($response->toolCalls as $tc) {
                    $name = $tc['function']['name'];
                    $args = json_decode($tc['function']['arguments'], true);
                    if (!is_array($args)) {
                        $args = [];
                    }
                    $tool = $this->tools->get($name);
                    $started = hrtime(true);
                    $inv = new AiToolInvocation($conversation->id(), $name, $args);
                    $inv->setMessageId($asstEntity->id());
                    if ($tool === null || ($allow !== [] && !in_array($name, $allow, true))) {
                        $result = ['error' => 'Tool not allowed'];
                        $ok = false;
                    } else {
                        try {
                            $toolResult = $tool->invoke($args, $actor);
                            $result = $toolResult->data;
                            $ok = $toolResult->ok;
                        } catch (\Throwable $e) {
                            $result = ['error' => $e->getMessage()];
                            $ok = false;
                        }
                    }
                    $duration = (int) ((hrtime(true) - $started) / 1_000_000);
                    $inv->complete($this->truncate($result), $ok, $duration);
                    $this->em->persist($inv);

                    $payload = json_encode($result, JSON_UNESCAPED_UNICODE);
                    $toolMsg = new AiMessage($conversation->id(), 'tool', is_string($payload) ? $payload : '');
                    $toolMsg->setTool($name, $tc['id']);
                    $this->em->persist($toolMsg);
                    $this->em->flush();

                    $messages[] = new ChatMessage('tool', is_string($payload) ? $payload : '', $name, $tc['id']);
                    $fire('tool', [
                        'name' => $name,
                        'ok' => $ok,
                        'duration_ms' => $duration,
                        'args' => $args,
                    ]);
                }
                // Next round: produce the spoken answer without tools so we can stream it.
                $openaiTools = [];
                continue;
            }

            if ($emit !== null && !$alreadyStreamed && $response->content !== '') {
                // Non-stream path (e.g. first round returned prose while tools were enabled).
                $parts = preg_split('/(?<=\s)/u', $response->content) ?: [$response->content];
                foreach ($parts as $part) {
                    if ($part !== '') {
                        $fire('delta', ['text' => $part]);
                        usleep(12_000);
                    }
                }
            }

            $finalText = $response->content;
            $asstEntity = new AiMessage($conversation->id(), 'assistant', $finalText);
            $asstEntity->setModelId($model->id());
            $asstEntity->setTokens($response->tokensIn, $response->tokensOut);
            $this->em->persist($asstEntity);
            $conversation->touch();
            $this->em->flush();
            $finalMessageId = $asstEntity->id();
            break;
        }

        if ($finalText === '' && $response !== null) {
            $finalText = $response->content;
        }

        $this->usage->record($provider->id(), $model->id(), $actor->surface, $agent->id(), $totalIn, $totalOut);

        $out = [
            'conversation_id' => $conversation->id(),
            'message' => $finalText,
            'message_id' => $finalMessageId,
            'citations' => $citations,
            'model' => $model->modelId(),
            'tokens_in' => $totalIn,
            'tokens_out' => $totalOut,
        ];
        $fire('done', $out);

        return $out;
    }

    private function truncate(mixed $data): mixed
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) > 8000) {
            return ['truncated' => true, 'preview' => substr($json, 0, 8000)];
        }

        return $data;
    }
}
