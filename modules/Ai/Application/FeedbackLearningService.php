<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Queue\Queue;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\ChatMessage;
use Modules\Ai\Application\Dto\ChatRequest;
use Modules\Ai\Application\Jobs\IngestSourceJob;
use Modules\Ai\Domain\AiAgent;
use Modules\Ai\Domain\AiConversation;
use Modules\Ai\Domain\AiFeedback;
use Modules\Ai\Domain\AiKnowledgeBase;
use Modules\Ai\Domain\AiMessage;
use Modules\Ai\Domain\AiSource;

final class FeedbackLearningService
{
    public const SHARED_KB_SLUG = 'learn-shared';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AgentService $agents,
        private readonly ModelRouter $router,
        private readonly Queue $queue,
    ) {
    }

    /**
     * @return array{feedback_id: string, rating: string, learned: bool}
     */
    public function record(
        string $conversationId,
        string $messageId,
        string $rating,
        AiActor $actor,
        ?string $note = null,
    ): array {
        if (!in_array($rating, AiFeedback::RATINGS, true)) {
            throw new \InvalidArgumentException('Invalid rating');
        }

        $conversation = $this->em->find(AiConversation::class, $conversationId);
        if (!$conversation instanceof AiConversation) {
            throw new \RuntimeException('Conversation not found', 404);
        }
        $message = $this->em->find(AiMessage::class, $messageId);
        if (!$message instanceof AiMessage || $message->conversationId() !== $conversationId) {
            throw new \RuntimeException('Message not found', 404);
        }
        if ($message->role() !== 'assistant') {
            throw new \RuntimeException('Feedback only for assistant messages', 422);
        }

        $agent = $this->em->find(AiAgent::class, $conversation->agentId());
        if (!$agent instanceof AiAgent) {
            throw new \RuntimeException('Agent not found', 404);
        }

        $existing = $this->em->getRepository(AiFeedback::class)->findOneBy(['messageId' => $messageId]);
        if ($existing instanceof AiFeedback) {
            $existing->setRating($rating);
            $existing->setNote($note !== null && trim($note) !== '' ? trim($note) : null);
            $feedback = $existing;
        } else {
            $feedback = new AiFeedback(
                $conversationId,
                $messageId,
                $agent->id(),
                $rating,
                $actor->type,
                $actor->id,
                $note !== null && trim($note) !== '' ? trim($note) : null,
            );
            $this->em->persist($feedback);
        }
        $this->em->flush();

        $learned = false;
        if ($feedback->isPositive() && $feedback->learnedSourceId() === null) {
            $learned = $this->learnFrom($feedback, $agent, $message);
        }

        return [
            'feedback_id' => $feedback->id(),
            'rating' => $feedback->rating(),
            'learned' => $learned,
        ];
    }

    private function learnFrom(AiFeedback $feedback, AiAgent $agent, AiMessage $assistantMsg): bool
    {
        $question = $this->precedingUserText($feedback->conversationId(), $assistantMsg);
        $answer = trim($assistantMsg->content());
        if ($question === '' || $answer === '') {
            return false;
        }

        $lesson = $this->distillLesson($question, $answer, $feedback->note(), $agent);
        $kb = $this->ensureSharedKb();
        $this->agents->ensureKnowledgeLink($agent, $kb->id());

        $source = $this->em->getRepository(AiSource::class)->findOneBy([
            'knowledgeBaseId' => $kb->id(),
            'sourceType' => 'manual_text',
            'sourceRef' => $feedback->id(),
        ]);
        $title = mb_substr('Feedback: ' . $question, 0, 240);
        if ($source instanceof AiSource) {
            $source->setTitle($title);
            $source->setAcl(['text' => $lesson, 'feedback_id' => $feedback->id(), 'agent_id' => $agent->id()]);
            $source->markPending();
        } else {
            $source = new AiSource($kb->id(), 'manual_text', $feedback->id(), $title, [
                'text' => $lesson,
                'feedback_id' => $feedback->id(),
                'agent_id' => $agent->id(),
            ]);
            $this->em->persist($source);
        }
        $this->em->flush();

        $feedback->setLearnedSourceId($source->id());
        $this->em->flush();

        $this->queue->dispatch(new IngestSourceJob($source->id()));

        return true;
    }

    private function precedingUserText(string $conversationId, AiMessage $assistantMsg): string
    {
        $rows = $this->em->getRepository(AiMessage::class)->createQueryBuilder('m')
            ->andWhere('m.conversationId = :c')->setParameter('c', $conversationId)
            ->andWhere('m.createdAt <= :at')->setParameter('at', $assistantMsg->createdAt())
            ->andWhere('m.role = :role')->setParameter('role', 'user')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        $row = $rows[0] ?? null;

        return $row instanceof AiMessage ? trim($row->content()) : '';
    }

    private function distillLesson(string $question, string $answer, ?string $note, AiAgent $agent): string
    {
        $fallback = $this->templateLesson($question, $answer, $note, $agent);
        try {
            $resolved = $this->router->resolveLlm($agent->chatModelId(), 'fast');
            $model = $resolved['model'];
            $prompt = "Z úspěšné konverzace vytvoř stručnou lekci pro knowledge base (čeština, Markdown).\n"
                . "Zachovej fakta, postupy a správný tón. Bez úvodních frází typu „zde je lekce“.\n"
                . "Struktura: ## Kontext, ## Správný postup / odpověď, ## Klíčové body (odrážky).\n";
            $user = "Agent: {$agent->name()} ({$agent->slug()})\n"
                . "Otázka uživatele:\n{$question}\n\n"
                . "Dobrá odpověď:\n{$answer}\n";
            if ($note) {
                $user .= "\nPoznámka k feedbacku:\n{$note}\n";
            }
            $resp = $this->router->chatWithModel($model, new ChatRequest(
                model: $model->modelId(),
                messages: [
                    new ChatMessage('system', $prompt),
                    new ChatMessage('user', $user),
                ],
                temperature: 0.2,
                tools: [],
            ));
            $text = trim($resp->content);
            if ($text !== '' && mb_strlen($text) > 40) {
                return $text . "\n\n---\nZdroj: pozitivní feedback · agent `{$agent->slug()}`\n";
            }
        } catch (\Throwable) {
            // Template fallback when LLM unavailable.
        }

        return $fallback;
    }

    private function templateLesson(string $question, string $answer, ?string $note, AiAgent $agent): string
    {
        $parts = [
            '## Naučeno z pozitivní zpětné vazby',
            '',
            '**Agent:** ' . $agent->name() . ' (`' . $agent->slug() . '`)',
            '',
            '### Otázka',
            $question,
            '',
            '### Ověřená odpověď',
            $answer,
        ];
        if ($note) {
            $parts[] = '';
            $parts[] = '### Poznámka';
            $parts[] = $note;
        }

        return implode("\n", $parts);
    }

    private function ensureSharedKb(): AiKnowledgeBase
    {
        $kb = $this->em->getRepository(AiKnowledgeBase::class)->findOneBy(['slug' => self::SHARED_KB_SLUG]);
        if ($kb instanceof AiKnowledgeBase) {
            return $kb;
        }
        $kb = new AiKnowledgeBase(
            self::SHARED_KB_SLUG,
            'Učení z feedbacku',
            'internal',
        );
        $kb->setDescription('Automaticky doplňované lekce z pozitivní zpětné vazby v chatech.');
        $this->em->persist($kb);
        $this->em->flush();

        return $kb;
    }
}
