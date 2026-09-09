<?php

declare(strict_types=1);

namespace Modules\Ai\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Ai\Application\AiOrchestrator;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\FeedbackLearningService;
use Modules\Ai\Domain\AiAgent;
use Modules\Ai\Domain\AiConversation;
use Modules\Ai\Domain\AiFeedback;
use Modules\Ai\Domain\AiMessage;
use Modules\Ai\Domain\AiModel;
use Modules\Ai\Domain\AiToolInvocation;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AiChatController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly AiOrchestrator $orchestrator,
        private readonly FeedbackLearningService $learning,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(Request $request, string $id): Response
    {
        $agent = $this->find($id);
        $conversationId = $request->query->get('conversation_id');
        $messages = [];
        $invocations = [];
        if (is_string($conversationId) && $conversationId !== '') {
            $messages = $this->em->getRepository(AiMessage::class)->findBy(
                ['conversationId' => $conversationId],
                ['createdAt' => 'ASC'],
                200,
            );
            $invocations = $this->em->getRepository(AiToolInvocation::class)->findBy(
                ['conversationId' => $conversationId],
                ['createdAt' => 'ASC'],
                200,
            );
        }

        $chatModel = null;
        if ($agent->chatModelId()) {
            $chatModel = $this->em->find(AiModel::class, $agent->chatModelId());
        }

        $timeline = $this->buildTimeline($messages, $invocations);
        $feedbackByMessage = [];
        if (is_string($conversationId) && $conversationId !== '') {
            foreach ($this->em->getRepository(AiFeedback::class)->findBy(['conversationId' => $conversationId]) as $fb) {
                if ($fb instanceof AiFeedback) {
                    $feedbackByMessage[$fb->messageId()] = $fb->rating();
                }
            }
        }
        $bootTimeline = [];
        foreach ($timeline as $item) {
            if (($item['type'] ?? '') === 'user') {
                /** @var AiMessage $m */
                $m = $item['message'];
                $bootTimeline[] = [
                    'type' => 'user',
                    'text' => $m->content(),
                    'time' => $m->createdAt()->format('H:i'),
                ];
            } elseif (($item['type'] ?? '') === 'assistant') {
                /** @var AiMessage $m */
                $m = $item['message'];
                $bootTimeline[] = [
                    'type' => 'assistant',
                    'text' => $m->content(),
                    'time' => $m->createdAt()->format('H:i'),
                    'messageId' => $m->id(),
                    'feedback' => $feedbackByMessage[$m->id()] ?? null,
                ];
            } elseif (($item['type'] ?? '') === 'reasoning') {
                $steps = [];
                foreach ($item['steps'] ?? [] as $step) {
                    $steps[] = [
                        'kind' => $step['kind'] ?? 'tool_call',
                        'name' => $step['name'] ?? 'tool',
                        'ok' => $step['ok'] ?? null,
                        'duration_ms' => $step['duration_ms'] ?? null,
                        'args' => $step['args'] ?? null,
                        'content' => $step['content'] ?? null,
                    ];
                }
                $bootTimeline[] = [
                    'type' => 'reasoning',
                    'open' => (bool) ($item['open'] ?? false),
                    'steps' => $steps,
                ];
            }
        }

        return $this->page('@ai/chat/show.html.twig', [
            'pageTitle' => $agent->name(),
            'agent' => $agent,
            'chat_model' => $chatModel instanceof AiModel ? $chatModel : null,
            'conversation_id' => is_string($conversationId) ? $conversationId : null,
            'stream_url' => $this->router->url('admin.ai.chat.stream', ['id' => $agent->id()]),
            'chat_url' => $this->router->url('admin.ai.chat', ['id' => $agent->id()]),
            'boot' => [
                'streamUrl' => $this->router->url('admin.ai.chat.stream', ['id' => $agent->id()]),
                'chatUrl' => $this->router->url('admin.ai.chat', ['id' => $agent->id()]),
                'feedbackUrl' => $this->router->url('admin.ai.chat.feedback', ['id' => $agent->id()]),
                'conversationId' => is_string($conversationId) ? $conversationId : null,
                'timeline' => $bootTimeline,
                'suggestions' => [
                    $this->translator->trans('ai.chat.suggest.overview'),
                    $this->translator->trans('ai.chat.suggest.secret'),
                    $this->translator->trans('ai.chat.suggest.search'),
                    $this->translator->trans('ai.chat.suggest.capabilities'),
                ],
                'labels' => [
                    'thinking' => $this->translator->trans('ai.chat.thinking'),
                    'generating' => $this->translator->trans('ai.chat.generating'),
                    'rag' => $this->translator->trans('ai.chat.status.rag'),
                    'tool' => $this->translator->trans('ai.chat.status.tool'),
                    'error' => $this->translator->trans('ai.chat.stream_error'),
                    'reasoning' => $this->translator->trans('ai.chat.reasoning'),
                    'toolLabel' => $this->translator->trans('ai.chat.tool'),
                    'thought' => $this->translator->trans('ai.chat.thought'),
                    'feedbackThanks' => $this->translator->trans('ai.chat.feedback.thanks'),
                    'feedbackLearned' => $this->translator->trans('ai.chat.feedback.learned'),
                    'feedbackError' => $this->translator->trans('ai.chat.feedback.error'),
                    'thumbUp' => $this->translator->trans('ai.chat.feedback.up'),
                    'thumbDown' => $this->translator->trans('ai.chat.feedback.down'),
                ],
            ],
        ]);
    }

    public function send(Request $request, string $id): Response
    {
        $agent = $this->find($id);
        $user = $this->auth->user();
        if ($user === null) {
            $this->abort(403);
        }
        $message = trim((string) $request->request->get('message', ''));
        if ($message === '') {
            $this->flash('error', $this->translator->trans('ai.error.empty_message'));

            return $this->redirect('admin.ai.chat', ['id' => $agent->id()]);
        }

        $perms = [];
        foreach (['customers.view', 'crm.view', 'listings.view', 'documents.view', 'emailing.view', 'ai.use', 'ai.manage'] as $p) {
            if ($user->can($p)) {
                $perms[] = $p;
            }
        }
        $actor = new AiActor('user', $user->id(), 'admin', $perms);
        try {
            $result = $this->orchestrator->chat(
                $agent,
                $actor,
                $message,
                $request->request->get('conversation_id') ? (string) $request->request->get('conversation_id') : null,
            );
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('admin.ai.chat', ['id' => $agent->id()]);
        }

        return $this->redirect('admin.ai.chat', [
            'id' => $agent->id(),
            'conversation_id' => $result['conversation_id'],
        ]);
    }

    public function stream(Request $request, string $id): StreamedResponse
    {
        $agent = $this->find($id);
        $user = $this->auth->user();
        if ($user === null) {
            $this->abort(403);
        }
        $message = trim((string) $request->request->get('message', ''));
        $conversationId = $request->request->get('conversation_id')
            ? (string) $request->request->get('conversation_id')
            : null;

        $perms = [];
        foreach (['customers.view', 'crm.view', 'listings.view', 'documents.view', 'emailing.view', 'ai.use', 'ai.manage'] as $p) {
            if ($user->can($p)) {
                $perms[] = $p;
            }
        }
        $actor = new AiActor('user', $user->id(), 'admin', $perms);

        return new StreamedResponse(function () use ($agent, $actor, $message, $conversationId): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            flush();

            $send = static function (array $event): void {
                echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            };

            if ($message === '') {
                $send(['type' => 'error', 'message' => $this->translator->trans('ai.error.empty_message')]);
                $send(['type' => 'done']);
                return;
            }

            try {
                $this->orchestrator->chatStream(
                    $agent,
                    $actor,
                    $message,
                    $send,
                    $conversationId,
                );
            } catch (\Throwable $e) {
                $send(['type' => 'error', 'message' => $e->getMessage()]);
                $send(['type' => 'done']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    public function feedback(Request $request, string $id): JsonResponse
    {
        $agent = $this->find($id);
        $user = $this->auth->user();
        if ($user === null) {
            $this->abort(403);
        }

        $conversationId = trim((string) $request->request->get('conversation_id', ''));
        $messageId = trim((string) $request->request->get('message_id', ''));
        $rating = trim((string) $request->request->get('rating', ''));
        $note = $request->request->get('note');
        $note = is_string($note) ? trim($note) : null;

        if ($conversationId === '' || $messageId === '' || !in_array($rating, ['positive', 'negative'], true)) {
            return new JsonResponse(['ok' => false, 'error' => 'validation_error'], 422);
        }

        $conversation = $this->em->find(AiConversation::class, $conversationId);
        if (!$conversation instanceof AiConversation || $conversation->agentId() !== $agent->id()) {
            return new JsonResponse(['ok' => false, 'error' => 'conversation_not_found'], 404);
        }

        $perms = [];
        foreach (['customers.view', 'crm.view', 'listings.view', 'documents.view', 'emailing.view', 'ai.use', 'ai.manage'] as $p) {
            if ($user->can($p)) {
                $perms[] = $p;
            }
        }
        $actor = new AiActor('user', $user->id(), 'admin', $perms);

        try {
            $result = $this->learning->record($conversationId, $messageId, $rating, $actor, $note);
        } catch (\Throwable $e) {
            $code = $e->getCode();
            $status = is_int($code) && $code >= 400 && $code < 600 ? $code : 400;

            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], $status);
        }

        return new JsonResponse(['ok' => true] + $result);
    }

    /**
     * @param list<AiMessage> $messages
     * @param list<AiToolInvocation> $invocations
     * @return list<array<string, mixed>>
     */
    private function buildTimeline(array $messages, array $invocations): array
    {
        $byMessage = [];
        foreach ($invocations as $inv) {
            if (!$inv instanceof AiToolInvocation) {
                continue;
            }
            $mid = $inv->messageId() ?? '_orphan';
            $byMessage[$mid][] = $inv;
        }

        $timeline = [];
        $pendingReasoning = null;

        foreach ($messages as $m) {
            if (!$m instanceof AiMessage || $m->role() === 'system') {
                continue;
            }

            if ($m->role() === 'user') {
                if ($pendingReasoning !== null) {
                    $timeline[] = $pendingReasoning;
                    $pendingReasoning = null;
                }
                $timeline[] = [
                    'type' => 'user',
                    'message' => $m,
                ];
                continue;
            }

            if ($m->role() === 'tool') {
                // Tool payloads are shown via AiToolInvocation on the assistant turn.
                continue;
            }

            if ($m->role() === 'assistant') {
                $linked = $byMessage[$m->id()] ?? [];
                unset($byMessage[$m->id()]);
                $hasTools = $linked !== [];
                $content = trim($m->content());

                if ($hasTools) {
                    if ($pendingReasoning === null) {
                        $pendingReasoning = [
                            'type' => 'reasoning',
                            'steps' => [],
                            'open' => false,
                        ];
                    }
                    if ($content !== '') {
                        $pendingReasoning['steps'][] = [
                            'kind' => 'thought',
                            'name' => 'thought',
                            'content' => $content,
                            'at' => $m->createdAt(),
                        ];
                    }
                    foreach ($linked as $inv) {
                        $pendingReasoning['steps'][] = [
                            'kind' => 'tool_call',
                            'name' => $inv->toolName(),
                            'args' => $inv->argsJson(),
                            'result' => $inv->resultJson(),
                            'ok' => $inv->ok(),
                            'duration_ms' => $inv->durationMs(),
                            'at' => $inv->createdAt(),
                        ];
                    }
                    // Intermediate tool-round assistants are not final answers.
                    continue;
                }

                if ($pendingReasoning !== null) {
                    $timeline[] = $pendingReasoning;
                    $pendingReasoning = null;
                }

                if ($content !== '') {
                    $timeline[] = [
                        'type' => 'assistant',
                        'message' => $m,
                    ];
                }
            }
        }

        if ($pendingReasoning !== null) {
            $timeline[] = $pendingReasoning;
            $pendingReasoning = null;
        }

        $orphans = $byMessage['_orphan'] ?? [];
        unset($byMessage['_orphan']);
        foreach ($byMessage as $left) {
            foreach ($left as $inv) {
                $orphans[] = $inv;
            }
        }
        if ($orphans !== []) {
            $steps = [];
            foreach ($orphans as $inv) {
                $steps[] = [
                    'kind' => 'tool_call',
                    'name' => $inv->toolName(),
                    'args' => $inv->argsJson(),
                    'result' => $inv->resultJson(),
                    'ok' => $inv->ok(),
                    'duration_ms' => $inv->durationMs(),
                    'at' => $inv->createdAt(),
                ];
            }
            $timeline[] = [
                'type' => 'reasoning',
                'steps' => $steps,
                'open' => true,
            ];
        }

        // Open the last reasoning block by default (most recent turn).
        for ($i = count($timeline) - 1; $i >= 0; $i--) {
            if (($timeline[$i]['type'] ?? '') === 'reasoning') {
                $timeline[$i]['open'] = true;
                break;
            }
        }

        return $timeline;
    }

    private function find(string $id): AiAgent
    {
        $agent = $this->em->find(AiAgent::class, $id);
        if (!$agent instanceof AiAgent) {
            $this->abort(404);
        }

        return $agent;
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
