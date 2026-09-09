<?php

declare(strict_types=1);

namespace Modules\Emailing\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Emailing\Application\EmailingProvider;
use Modules\Emailing\Application\MailboxSyncService;
use Modules\Emailing\Domain\MailAccount;
use Modules\Emailing\Domain\MailAttachment;
use Modules\Emailing\Domain\MailFolder;
use Modules\Emailing\Domain\MailMessage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class MailboxApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly EmailingProvider $provider,
        private readonly MailboxSyncService $sync,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function folders(Request $request): JsonResponse
    {
        $accountId = (string) $request->query->get('account_id', '');
        if ($accountId === '') {
            return ApiResponse::error('validation_error', 'account_id je povinný.', [], 422);
        }
        $folders = $this->em->getRepository(MailFolder::class)->findBy(['accountId' => $accountId], ['name' => 'ASC']);

        return ApiResponse::collection(array_map(static fn (MailFolder $f): array => [
            'id' => $f->id(),
            'account_id' => $f->accountId(),
            'name' => $f->name(),
            'remote_path' => $f->remotePath(),
            'role' => $f->role(),
            'unread_count' => $f->unreadCount(),
            'total_count' => $f->totalCount(),
        ], $folders));
    }

    public function messages(Request $request): JsonResponse
    {
        $folderId = (string) $request->query->get('folder_id', '');
        if ($folderId === '') {
            return ApiResponse::error('validation_error', 'folder_id je povinný.', [], 422);
        }
        $limit = min(200, max(1, (int) $request->query->get('limit', 50)));
        $rows = $this->em->getRepository(MailMessage::class)->findBy(
            ['folderId' => $folderId],
            ['dateAt' => 'DESC'],
            $limit,
        );

        return ApiResponse::collection(array_map($this->serializeMessage(...), $rows));
    }

    public function show(string $id): JsonResponse
    {
        $message = $this->em->find(MailMessage::class, $id);
        if (!$message instanceof MailMessage) {
            return ApiResponse::notFound();
        }

        $account = $this->em->find(MailAccount::class, $message->accountId());
        $body = null;
        if ($account instanceof MailAccount) {
            try {
                $body = $this->sync->fetchBody($account, $message);
            } catch (\Throwable $e) {
                return ApiResponse::error('sync_error', $e->getMessage(), [], 502);
            }
        }

        $attachments = $this->em->getRepository(MailAttachment::class)->findBy(['messageId' => $message->id()]);
        $payload = $this->serializeMessage($message);
        $payload['body_html'] = $body?->textHtml();
        $payload['body_text'] = $body?->textPlain();
        $payload['attachments'] = array_map(static fn (MailAttachment $a): array => [
            'id' => $a->id(),
            'filename' => $a->filename(),
            'mime' => $a->mime(),
            'size' => $a->size(),
        ], $attachments);

        return ApiResponse::data($payload);
    }

    public function send(Request $request): JsonResponse
    {
        $raw = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($raw)) {
            return ApiResponse::error('validation_error', 'Neplatné JSON tělo.', [], 422);
        }
        $to = $raw['to'] ?? null;
        $subject = trim((string) ($raw['subject'] ?? ''));
        if ($to === null || $to === '' || $subject === '') {
            return ApiResponse::error('validation_error', 'to a subject jsou povinné.', [], 422);
        }

        try {
            $outbox = $this->provider->send(
                $to,
                $subject,
                isset($raw['html']) ? (string) $raw['html'] : (isset($raw['body_html']) ? (string) $raw['body_html'] : null),
                isset($raw['text']) ? (string) $raw['text'] : (isset($raw['body_text']) ? (string) $raw['body_text'] : null),
                isset($raw['account_id']) ? (string) $raw['account_id'] : null,
                $raw['cc'] ?? null,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('send_error', $e->getMessage(), [], 422);
        }

        return ApiResponse::data([
            'id' => $outbox->id(),
            'status' => $outbox->status(),
            'account_id' => $outbox->accountId(),
        ], 201);
    }

    /** @return array<string, mixed> */
    private function serializeMessage(MailMessage $m): array
    {
        return [
            'id' => $m->id(),
            'account_id' => $m->accountId(),
            'folder_id' => $m->folderId(),
            'uid' => $m->uid(),
            'message_id' => $m->messageId(),
            'from_address' => $m->fromAddress(),
            'from_name' => $m->fromName(),
            'to_addresses' => $m->toAddresses(),
            'subject' => $m->subject(),
            'snippet' => $m->snippet(),
            'date_at' => $m->dateAt()?->format(\DateTimeInterface::ATOM),
            'is_seen' => $m->isSeen(),
            'is_flagged' => $m->isFlagged(),
            'has_attachments' => $m->hasAttachments(),
            'has_body' => $m->hasBody(),
        ];
    }
}
