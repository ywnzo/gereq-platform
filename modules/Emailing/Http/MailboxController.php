<?php

declare(strict_types=1);

namespace Modules\Emailing\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Queue\Queue;
use Gereq\Security\ContentSecurityPolicy;
use Modules\Emailing\Application\BimiLogoResolver;
use Modules\Emailing\Application\ImapKeyword;
use Modules\Emailing\Application\Jobs\SyncAccountJob;
use Modules\Emailing\Application\MailboxActionService;
use Modules\Emailing\Application\MailboxSyncService;
use Modules\Emailing\Application\MailThreadResolver;
use Modules\Emailing\Application\MimeHeaderDecoder;
use Modules\Emailing\Application\SmtpSender;
use Modules\Emailing\Domain\MailAccount;
use Modules\Emailing\Domain\MailAttachment;
use Modules\Emailing\Domain\MailBody;
use Modules\Emailing\Domain\MailFolder;
use Modules\Emailing\Domain\MailLabel;
use Modules\Emailing\Domain\MailMessage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MailboxController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly SmtpSender $smtp,
        private readonly MailboxSyncService $sync,
        private readonly MailboxActionService $actions,
        private readonly Queue $queue,
        private readonly BimiLogoResolver $bimi,
        private readonly ContentSecurityPolicy $csp,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $accounts = $this->em->getRepository(MailAccount::class)->findBy(['isActive' => true], ['name' => 'ASC']);
        $accountId = (string) $request->query->get('account', '');
        $q = trim((string) $request->query->get('q', ''));
        $fromFilter = trim((string) $request->query->get('from', ''));
        $unreadOnly = $request->query->get('unread') === '1' || $request->query->get('unread') === 'true';
        $hasAttachmentOnly = $request->query->get('has_attachment') === '1'
            || $request->query->get('has_attachment') === 'true';
        $activeTagKeywords = $this->parseTagFilters($request);
        $account = null;
        if ($accountId !== '') {
            $account = $this->em->find(MailAccount::class, $accountId);
        }
        if (!$account instanceof MailAccount && $accounts !== []) {
            $account = $accounts[0];
        }

        $folders = [];
        $messages = [];
        $folderId = (string) $request->query->get('folder', '');
        $selectedFolder = null;
        $mailboxSyncError = null;
        if ($account instanceof MailAccount) {
            $folders = $this->em->getRepository(MailFolder::class)->findBy(
                ['accountId' => $account->id()],
            );
            if ($folders === []) {
                try {
                    $this->sync->syncAccount($account, 50);
                } catch (\Throwable $e) {
                    $mailboxSyncError = $e->getMessage();
                    error_log('[emailing.sync] ' . $account->email() . ': ' . $e->getMessage());
                }
                $folders = $this->em->getRepository(MailFolder::class)->findBy(
                    ['accountId' => $account->id()],
                );
            }
            $this->sortMailFolders($folders);

            if ($folderId !== '') {
                $selectedFolder = $this->em->find(MailFolder::class, $folderId);
                if ($selectedFolder instanceof MailFolder && $selectedFolder->accountId() !== $account->id()) {
                    $selectedFolder = null;
                }
            }
            if (!$selectedFolder instanceof MailFolder) {
                foreach ($folders as $f) {
                    if ($f->role() === 'inbox') {
                        $selectedFolder = $f;
                        break;
                    }
                }
                $selectedFolder ??= $folders[0] ?? null;
            }
            if ($selectedFolder instanceof MailFolder) {
                $qb = $this->em->getRepository(MailMessage::class)->createQueryBuilder('m')
                    ->where('m.folderId = :fid')->setParameter('fid', $selectedFolder->id())
                    ->orderBy('m.dateAt', 'DESC')
                    ->setMaxResults(100);
                if ($q !== '') {
                    $qb->leftJoin(MailBody::class, 'b', 'WITH', 'b.messageId = m.id')
                        ->andWhere(
                            'm.subject LIKE :q OR m.fromName LIKE :q OR m.fromAddress LIKE :q OR m.snippet LIKE :q'
                            . ' OR b.textPlain LIKE :q OR b.textHtml LIKE :q'
                        )
                        ->setParameter('q', '%' . $q . '%');
                }
                foreach ($activeTagKeywords as $i => $keyword) {
                    $qb->andWhere('m.keywords LIKE :tag' . $i)
                        ->setParameter('tag' . $i, '%"' . addcslashes($keyword, '%_\\') . '"%');
                }
                if ($fromFilter !== '') {
                    $qb->andWhere('m.fromAddress LIKE :from OR m.fromName LIKE :from')
                        ->setParameter('from', '%' . addcslashes($fromFilter, '%_\\') . '%');
                }
                if ($unreadOnly) {
                    $qb->andWhere('m.isSeen = false');
                }
                if ($hasAttachmentOnly) {
                    $qb->andWhere('m.hasAttachments = true');
                }
                /** @var list<MailMessage> $messages */
                $messages = $qb->getQuery()->getResult();
                $this->repairEncodedHeaders($messages);
                $this->ensureThreadIds($messages);
                $this->bimi->preload(array_map(
                    static fn (MailMessage $m): ?string => $m->fromAddress(),
                    $messages,
                ));
            }
        }

        $messageId = (string) $request->query->get('message', '');
        $selectedMessage = $messageId !== '' ? $this->em->find(MailMessage::class, $messageId) : null;
        if ($selectedMessage instanceof MailMessage && $account instanceof MailAccount
            && $selectedMessage->accountId() !== $account->id()) {
            $selectedMessage = null;
        }
        if ($selectedMessage instanceof MailMessage) {
            $this->repairEncodedHeaders([$selectedMessage]);
            $this->ensureThreadIds([$selectedMessage]);
        }
        $body = null;
        $attachments = [];
        $threadMessages = [];
        if ($selectedMessage instanceof MailMessage && $account instanceof MailAccount) {
            if (!$selectedMessage->isSeen()) {
                try {
                    $this->actions->markSeen($account, $selectedMessage, true);
                    $selectedMessage = $this->em->find(MailMessage::class, $selectedMessage->id()) ?? $selectedMessage;
                } catch (\Throwable $e) {
                    error_log('[emailing.markSeen] ' . $e->getMessage());
                }
            }
            try {
                // fetchBody přepíše staré HTML s cid: (CSP object-src / img).
                $body = $this->sync->fetchBody($account, $selectedMessage);
                $selectedMessage = $this->em->find(MailMessage::class, $selectedMessage->id()) ?? $selectedMessage;
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage());
                $body = $this->em->getRepository(MailBody::class)->findOneBy(['messageId' => $selectedMessage->id()]);
            }
            /** @var list<MailAttachment> $rawAttachments */
            $rawAttachments = $this->em->getRepository(MailAttachment::class)->findBy(['messageId' => $selectedMessage->id()]);
            foreach ($rawAttachments as $att) {
                $name = MimeHeaderDecoder::decode($att->filename());
                if ($name !== '' && $name !== $att->filename()) {
                    $att->setFilename(mb_substr($name, 0, 255));
                }
                // Oprava starších synců: PDF s CID bylo is_inline=true.
                if ($att->isInline() && $att->isDownloadable()) {
                    $att->setInline(false);
                }
                if (!$att->isDownloadable()) {
                    continue;
                }
                $mime = strtolower($att->mime());
                $kind = $this->attachmentKind($att->filename(), $mime);
                $attachments[] = [
                    'id' => $att->id(),
                    'filename' => $att->filename(),
                    'size' => $att->size(),
                    'mime' => $att->mime(),
                    'is_image' => $kind === 'image',
                    'kind' => $kind,
                    'badge' => $this->attachmentBadge($kind),
                    'url' => $this->router->url('admin.emailing.attachments.download', ['id' => $att->id()]),
                ];
            }
            if ($rawAttachments !== []) {
                $this->em->flush();
            }

            $threadId = $selectedMessage->threadId();
            if ($threadId) {
                /** @var list<MailMessage> $threadMessages */
                $threadMessages = $this->em->createQueryBuilder()
                    ->select('m')
                    ->from(MailMessage::class, 'm')
                    ->where('m.accountId = :aid')
                    ->andWhere('m.threadId = :tid')
                    ->setParameter('aid', $account->id())
                    ->setParameter('tid', $threadId)
                    ->orderBy('m.dateAt', 'ASC')
                    ->setMaxResults(50)
                    ->getQuery()->getResult();
                $this->ensureThreadIds($threadMessages);
                $this->bimi->preload(array_map(
                    static fn (MailMessage $m): ?string => $m->fromAddress(),
                    $threadMessages,
                ));
            }
        }

        $rows = $this->presentThreads($messages);
        $rowGroups = $this->groupRowsByDate($rows);
        $selectedPresented = $selectedMessage instanceof MailMessage ? $this->presentMessage($selectedMessage) : null;
        $threadRows = array_map(fn (MailMessage $m): array => $this->presentMessage($m), $threadMessages);
        $labels = [];
        if ($account instanceof MailAccount) {
            /** @var list<MailLabel> $labelRows */
            $labelRows = $this->em->getRepository(MailLabel::class)->findBy(
                ['accountId' => $account->id()],
                ['name' => 'ASC'],
            );
            $labels = array_values(array_filter(array_map(static function (MailLabel $l): ?array {
                if ($l->keyword() === ImapKeyword::PINNED) {
                    return null;
                }

                return [
                    'id' => $l->id(),
                    'name' => $l->name(),
                    'keyword' => $l->keyword(),
                    'color' => $l->color(),
                ];
            }, $labelRows)));
        }
        $folderPayload = array_map(static fn (MailFolder $f): array => [
            'id' => $f->id(),
            'name' => $f->name(),
            'role' => $f->role(),
            'color' => $f->color(),
            'favorite' => $f->isFavorite(),
            'system' => $f->isSystemRole(),
            'unread' => $f->unreadCount(),
            'total' => $f->totalCount(),
        ], $folders);

        $labelByKeyword = [];
        foreach ($labels as $lab) {
            $labelByKeyword[(string) $lab['keyword']] = $lab;
        }
        $activeTags = [];
        foreach ($activeTagKeywords as $keyword) {
            $lab = $labelByKeyword[$keyword] ?? null;
            $activeTags[] = [
                'keyword' => $keyword,
                'name' => is_array($lab) ? (string) $lab['name'] : $keyword,
                'color' => is_array($lab) ? ($lab['color'] ?? null) : null,
            ];
        }
        $listFilters = [
            'from' => $fromFilter,
            'unread' => $unreadOnly,
            'has_attachment' => $hasAttachmentOnly,
        ];
        $listQs = $this->mailboxListQuery(
            $account instanceof MailAccount ? $account->id() : null,
            $q,
            $activeTagKeywords,
            $listFilters,
        );
        $folderSuffix = $selectedFolder instanceof MailFolder
            ? (($listQs !== '' ? '&' : '') . 'folder=' . rawurlencode($selectedFolder->id()))
            : '';
        $tagRemoveQuery = [];
        foreach ($activeTagKeywords as $keyword) {
            $remaining = array_values(array_filter(
                $activeTagKeywords,
                static fn (string $k): bool => $k !== $keyword,
            ));
            $tagRemoveQuery[$keyword] = $this->mailboxListQuery(
                $account instanceof MailAccount ? $account->id() : null,
                $q,
                $remaining,
                $listFilters,
            ) . $folderSuffix;
        }
        $filterRemoveQuery = [
            'from' => $this->mailboxListQuery(
                $account instanceof MailAccount ? $account->id() : null,
                $q,
                $activeTagKeywords,
                ['from' => '', 'unread' => $unreadOnly, 'has_attachment' => $hasAttachmentOnly],
            ) . $folderSuffix,
            'unread' => $this->mailboxListQuery(
                $account instanceof MailAccount ? $account->id() : null,
                $q,
                $activeTagKeywords,
                ['from' => $fromFilter, 'unread' => false, 'has_attachment' => $hasAttachmentOnly],
            ) . $folderSuffix,
            'has_attachment' => $this->mailboxListQuery(
                $account instanceof MailAccount ? $account->id() : null,
                $q,
                $activeTagKeywords,
                ['from' => $fromFilter, 'unread' => $unreadOnly, 'has_attachment' => false],
            ) . $folderSuffix,
        ];

        $signatures = [];
        foreach ($accounts as $acc) {
            /** @var MailAccount $acc */
            $sig = $acc->signatureHtml();
            if ($sig !== null && $sig !== '') {
                $signatures[$acc->id()] = $sig;
            }
        }

        return $this->page('@emailing/mailbox/index.html.twig', [
            'pageTitle' => $this->translator->trans('emailing.mailbox.title'),
            'accounts' => $accounts,
            'account' => $account,
            'folders' => $folders,
            'folderPayload' => $folderPayload,
            'folder' => $selectedFolder,
            'messages' => $messages,
            'rows' => $rows,
            'rowGroups' => $rowGroups,
            'message' => $selectedMessage instanceof MailMessage ? $selectedMessage : null,
            'selected' => $selectedPresented,
            'mailBody' => $body,
            'attachments' => $attachments,
            'threadRows' => $threadRows,
            'labels' => $labels,
            'actionUrl' => $this->router->url('admin.emailing.action'),
            'q' => $q,
            'fromFilter' => $fromFilter,
            'unreadOnly' => $unreadOnly,
            'hasAttachmentOnly' => $hasAttachmentOnly,
            'activeTags' => $activeTags,
            'activeTagKeywords' => $activeTagKeywords,
            'listQs' => $listQs,
            'tagRemoveQuery' => $tagRemoveQuery,
            'filterRemoveQuery' => $filterRemoveQuery,
            'signatures' => $signatures,
            'mailboxSyncError' => $mailboxSyncError,
            'bulkIds' => array_values(array_map(
                static fn (array $row): string => (string) ($row['id'] ?? ''),
                $rows,
            )),
        ]);
    }

    public function messageHtml(string $id): Response
    {
        $message = $this->em->find(MailMessage::class, $id);
        if (!$message instanceof MailMessage) {
            $this->abort(404);
        }

        $account = $this->em->find(MailAccount::class, $message->accountId());
        if (!$account instanceof MailAccount) {
            $this->abort(404);
        }

        $body = null;
        try {
            $body = $this->sync->fetchBody($account, $message);
        } catch (\Throwable) {
            $body = $this->em->getRepository(MailBody::class)->findOneBy(['messageId' => $message->id()]);
        }

        $html = is_string($body?->textHtml()) ? (string) $body->textHtml() : '';
        if ($html === '') {
            $this->abort(404);
        }

        $response = new Response($this->wrapEmailHtmlDocument($html));
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        $response->headers->set(ContentSecurityPolicy::HEADER_OVERRIDE, $this->csp->emailPreviewHeader());
        $response->headers->set(ContentSecurityPolicy::HEADER_ALLOW_FRAME, '1');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function downloadAttachment(string $id): Response
    {
        $attachment = $this->em->find(MailAttachment::class, $id);
        if (!$attachment instanceof MailAttachment) {
            $this->abort(404);
        }

        $message = $this->em->find(MailMessage::class, $attachment->messageId());
        if (!$message instanceof MailMessage) {
            $this->abort(404);
        }

        $root = realpath(base_path('storage/uploads'));
        if ($root === false) {
            $this->abort(404);
        }
        $path = realpath($root . DIRECTORY_SEPARATOR . ltrim($attachment->path(), '/\\'));
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            $this->abort(404);
        }

        $filename = MimeHeaderDecoder::decode($attachment->filename()) ?: 'attachment.bin';
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $attachment->mime() !== '' ? $attachment->mime() : 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    public function compose(Request $request): Response
    {
        $query = array_filter([
            'compose' => '1',
            'account' => (string) $request->query->get('account', ''),
            'to' => (string) $request->query->get('to', ''),
            'cc' => (string) $request->query->get('cc', ''),
            'subject' => (string) $request->query->get('subject', ''),
            'body' => (string) $request->query->get('body', ''),
        ], static fn (string $v): bool => $v !== '');

        return $this->redirectTo($this->router->url('admin.emailing.mailbox') . '?' . http_build_query($query));
    }

    public function send(Request $request): Response
    {
        $data = $this->validate($request, [
            'account_id' => 'required',
            'to' => 'required|max:2000',
            'cc' => 'nullable|max:2000',
            'subject' => 'required|max:500',
            'body_html' => 'nullable|max:200000',
            'body_text' => 'nullable|max:200000',
            'in_reply_to' => 'nullable|max:255',
            'references' => 'nullable|max:4000',
        ]);

        $account = $this->em->find(MailAccount::class, (string) $data['account_id']);
        if (!$account instanceof MailAccount) {
            $this->abort(404, $this->translator->trans('emailing.error.account_not_found'));
        }

        $outbox = $this->smtp->queue(
            $account,
            (string) $data['to'],
            (string) $data['subject'],
            isset($data['body_html']) ? (string) $data['body_html'] : null,
            isset($data['body_text']) ? (string) $data['body_text'] : null,
            isset($data['cc']) ? (string) $data['cc'] : null,
            isset($data['in_reply_to']) ? (string) $data['in_reply_to'] : null,
            isset($data['references']) ? (string) $data['references'] : null,
        );
        $this->auditor->log('emailing.message.queued', 'emailing_outbox', $outbox->id());
        $this->flash('success', $this->translator->trans('emailing.flash.message_queued'));

        return $this->redirect('admin.emailing.mailbox');
    }

    public function sync(Request $request): Response
    {
        $accountId = (string) $request->request->get('account_id', '');
        $account = $this->em->find(MailAccount::class, $accountId);
        if (!$account instanceof MailAccount) {
            $this->abort(404, $this->translator->trans('emailing.error.account_not_found'));
        }

        $folderCount = (int) $this->em->getRepository(MailFolder::class)->count(['accountId' => $account->id()]);
        if ($folderCount === 0 || $account->lastSyncedAt() === null) {
            try {
                $this->sync->syncAccount($account, 80);
                $this->flash('success', $this->translator->trans('emailing.flash.sync_done'));
            } catch (\Throwable $e) {
                error_log('[emailing.sync] ' . $account->email() . ': ' . $e->getMessage());
                $this->flash('error', $this->translator->trans('emailing.flash.sync_failed', [
                    '%error%' => $e->getMessage(),
                ]));
            }
        } else {
            $this->queue->dispatch(new SyncAccountJob($account->id()));
            if ($this->wantsJson($request)) {
                return new JsonResponse(['data' => ['queued' => true]]);
            }
            $this->flash('success', $this->translator->trans('emailing.flash.sync_queued'));
        }

        if ($this->wantsJson($request)) {
            return new JsonResponse(['data' => ['synced' => true]]);
        }

        return $this->redirectTo($this->router->url('admin.emailing.mailbox') . '?account=' . urlencode($account->id()));
    }

    public function poll(Request $request): JsonResponse
    {
        $accountId = (string) $request->query->get('account', '');
        $account = $this->em->find(MailAccount::class, $accountId);
        if (!$account instanceof MailAccount) {
            return new JsonResponse(['error' => 'account_not_found'], 404);
        }

        try {
            $result = $this->sync->pollAndReconcile($account);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'poll_failed',
                'message' => $e->getMessage(),
            ], 502);
        }

        return new JsonResponse(['data' => $result]);
    }

    public function action(Request $request): JsonResponse
    {
        $raw = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($raw)) {
            return new JsonResponse(['error' => 'invalid_json'], 422);
        }
        $action = (string) ($raw['action'] ?? '');
        $account = $this->em->find(MailAccount::class, (string) ($raw['account_id'] ?? ''));
        if (!$account instanceof MailAccount) {
            return new JsonResponse(['error' => 'account_not_found'], 404);
        }

        try {
            $payload = $this->dispatchMailboxAction($account, $action, $raw);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => 'validation_error', 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'action_failed', 'message' => $e->getMessage()], 502);
        }

        return new JsonResponse(['data' => $payload]);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function dispatchMailboxAction(MailAccount $account, string $action, array $raw): array
    {
        $folder = isset($raw['folder_id']) ? $this->em->find(MailFolder::class, (string) $raw['folder_id']) : null;
        $message = isset($raw['message_id']) ? $this->em->find(MailMessage::class, (string) $raw['message_id']) : null;
        $target = isset($raw['target_folder_id']) ? $this->em->find(MailFolder::class, (string) $raw['target_folder_id']) : null;

        return match ($action) {
            'message.seen' => $this->requireMessage($message, function (MailMessage $m) use ($account, $raw): array {
                $this->actions->markSeen($account, $m, (bool) ($raw['seen'] ?? true));

                return ['ok' => true, 'reload' => true];
            }),
            'message.flag' => $this->requireMessage($message, function (MailMessage $m) use ($account, $raw): array {
                $this->actions->markFlagged($account, $m, (bool) ($raw['flagged'] ?? true));

                return ['ok' => true, 'reload' => true];
            }),
            'message.pin' => $this->requireMessage($message, function (MailMessage $m) use ($account, $raw): array {
                $this->actions->markPinned($account, $m, (bool) ($raw['pinned'] ?? true));

                return ['ok' => true, 'reload' => true];
            }),
            'message.keyword' => $this->requireMessage($message, function (MailMessage $m) use ($account, $raw): array {
                $this->actions->toggleKeyword($account, $m, (string) ($raw['keyword'] ?? ''), (bool) ($raw['enable'] ?? true));

                return ['ok' => true, 'reload' => true];
            }),
            'message.delete' => $this->requireMessage($message, function (MailMessage $m) use ($account): array {
                $this->actions->deleteMessage($account, $m);

                return ['ok' => true, 'reload' => true];
            }),
            'message.archive' => $this->requireMessage($message, function (MailMessage $m) use ($account): array {
                $this->actions->archiveMessage($account, $m);

                return ['ok' => true, 'reload' => true];
            }),
            'message.move' => $this->requireMessage($message, function (MailMessage $m) use ($account, $target): array {
                if (!$target instanceof MailFolder) {
                    throw new \InvalidArgumentException('Cílová složka chybí.');
                }
                $this->actions->moveMessage($account, $m, $target);

                return ['ok' => true, 'reload' => true];
            }),
            'message.copy' => $this->requireMessage($message, function (MailMessage $m) use ($account, $target): array {
                if (!$target instanceof MailFolder) {
                    throw new \InvalidArgumentException('Cílová složka chybí.');
                }
                $this->actions->copyMessage($account, $m, $target);

                return ['ok' => true, 'reload' => false];
            }),
            'message.bulk' => (function () use ($account, $raw, $target): array {
                $ids = $raw['message_ids'] ?? [];
                if (!is_array($ids) || $ids === []) {
                    throw new \InvalidArgumentException('Vyberte alespoň jednu zprávu.');
                }
                $op = (string) ($raw['op'] ?? '');
                $count = 0;
                foreach ($ids as $id) {
                    $m = $this->em->find(MailMessage::class, (string) $id);
                    if (!$m instanceof MailMessage || $m->accountId() !== $account->id()) {
                        continue;
                    }
                    match ($op) {
                        'seen' => $this->actions->markSeen($account, $m, true),
                        'unseen' => $this->actions->markSeen($account, $m, false),
                        'flag' => $this->actions->markFlagged($account, $m, true),
                        'unflag' => $this->actions->markFlagged($account, $m, false),
                        'delete' => $this->actions->deleteMessage($account, $m),
                        'archive' => $this->actions->archiveMessage($account, $m),
                        'move' => (function () use ($account, $m, $target): void {
                            if (!$target instanceof MailFolder) {
                                throw new \InvalidArgumentException('Cílová složka chybí.');
                            }
                            $this->actions->moveMessage($account, $m, $target);
                        })(),
                        default => throw new \InvalidArgumentException('Neznámá hromadná akce.'),
                    };
                    $count++;
                }

                return ['ok' => true, 'reload' => true, 'count' => $count];
            })(),
            'label.create' => (function () use ($account, $raw): array {
                $label = $this->actions->createLabel($account, (string) ($raw['name'] ?? ''), isset($raw['color']) ? (string) $raw['color'] : null);

                return ['ok' => true, 'reload' => false, 'label' => [
                    'id' => $label->id(),
                    'name' => $label->name(),
                    'keyword' => $label->keyword(),
                    'color' => $label->color(),
                ]];
            })(),
            'folder.mark_all_read' => $this->requireFolder($folder, function (MailFolder $f) use ($account): array {
                $this->actions->markAllRead($account, $f);

                return ['ok' => true, 'reload' => true];
            }),
            'folder.empty' => $this->requireFolder($folder, function (MailFolder $f) use ($account): array {
                $this->actions->emptyFolder($account, $f);

                return ['ok' => true, 'reload' => true];
            }),
            'folder.create' => $this->requireFolder($folder, function (MailFolder $f) use ($account, $raw): array {
                $created = $this->actions->createSubfolder($account, $f, (string) ($raw['name'] ?? ''));

                return ['ok' => true, 'reload' => true, 'folder_id' => $created->id()];
            }),
            'folder.rename' => $this->requireFolder($folder, function (MailFolder $f) use ($account, $raw): array {
                $this->actions->renameFolder($account, $f, (string) ($raw['name'] ?? ''));

                return ['ok' => true, 'reload' => true];
            }),
            'folder.delete' => $this->requireFolder($folder, function (MailFolder $f) use ($account): array {
                $this->actions->deleteFolder($account, $f);

                return ['ok' => true, 'reload' => true];
            }),
            'folder.color' => $this->requireFolder($folder, function (MailFolder $f) use ($raw): array {
                $this->actions->setFolderColor($f, isset($raw['color']) ? (string) $raw['color'] : null);

                return ['ok' => true, 'reload' => true];
            }),
            'folder.favorite' => $this->requireFolder($folder, function (MailFolder $f) use ($raw): array {
                $this->actions->setFolderFavorite($f, (bool) ($raw['favorite'] ?? true));

                return ['ok' => true, 'reload' => true];
            }),
            'folder.order' => $this->requireFolder($folder, function (MailFolder $f) use ($account, $raw): array {
                $this->actions->moveFolderOrder($account, $f, (string) ($raw['direction'] ?? 'up'));

                return ['ok' => true, 'reload' => true];
            }),
            default => throw new \InvalidArgumentException('Neznámá akce.'),
        };
    }

    /** @param callable(MailMessage): array<string, mixed> $fn */
    private function requireMessage(?object $message, callable $fn): array
    {
        if (!$message instanceof MailMessage) {
            throw new \InvalidArgumentException('Zpráva nenalezena.');
        }

        return $fn($message);
    }

    /** @param callable(MailFolder): array<string, mixed> $fn */
    private function requireFolder(?object $folder, callable $fn): array
    {
        if (!$folder instanceof MailFolder) {
            throw new \InvalidArgumentException('Složka nenalezena.');
        }

        return $fn($folder);
    }

    /** @param list<MailMessage> $messages */
    private function repairEncodedHeaders(array $messages): void
    {
        $dirty = false;
        foreach ($messages as $message) {
            $subject = MimeHeaderDecoder::decode($message->subject());
            $fromName = MimeHeaderDecoder::decode($message->fromName());
            $changed = false;
            if ($subject !== (string) $message->subject()) {
                $changed = true;
            }
            if ($fromName !== (string) $message->fromName()) {
                $changed = true;
            }
            if ($changed) {
                $message->applyHeaders(
                    $message->messageId(),
                    $message->fromAddress(),
                    $fromName !== '' ? $fromName : $message->fromName(),
                    $message->toAddresses(),
                    $subject !== '' ? mb_substr($subject, 0, 500) : $message->subject(),
                    $subject !== '' ? mb_substr($subject, 0, 180) : $message->snippet(),
                    $message->dateAt(),
                    $message->isSeen(),
                    $message->isFlagged(),
                    $message->hasAttachments(),
                    $message->sizeBytes(),
                );
                $dirty = true;
            }
        }
        if ($dirty) {
            $this->em->flush();
        }
    }

    /** @param list<MailMessage> $messages */
    private function ensureThreadIds(array $messages): void
    {
        /** @var list<MailMessage> $pending */
        $pending = [];
        foreach ($messages as $message) {
            if (!$message->threadId()) {
                $pending[] = $message;
            }
        }
        if ($pending === []) {
            return;
        }

        $accountIds = [];
        $inReplyIds = [];
        /** @var array<string, array<string, true>> $subjectsByAccount */
        $subjectsByAccount = [];
        foreach ($pending as $message) {
            $accountIds[$message->accountId()] = true;
            $irt = $message->inReplyTo();
            if ($irt !== null && $irt !== '') {
                $inReplyIds[$irt] = true;
            }
            $threadSubject = MailThreadResolver::normalizeSubject($message->subject());
            if ($threadSubject !== '') {
                $subjectsByAccount[$message->accountId()][$threadSubject] = true;
            }
        }

        /** @var array<string, string> $parentThreadByAccountMsgId */
        $parentThreadByAccountMsgId = [];
        if ($inReplyIds !== []) {
            /** @var list<MailMessage> $parents */
            $parents = $this->em->createQueryBuilder()
                ->select('m')
                ->from(MailMessage::class, 'm')
                ->where('m.accountId IN (:aids)')
                ->andWhere('m.messageId IN (:mids)')
                ->setParameter('aids', array_keys($accountIds))
                ->setParameter('mids', array_keys($inReplyIds))
                ->getQuery()->getResult();
            foreach ($parents as $parent) {
                $mid = $parent->messageId();
                $tid = $parent->threadId();
                if ($mid !== null && $tid !== null && $tid !== '') {
                    $parentThreadByAccountMsgId[$parent->accountId() . "\0" . $mid] = $tid;
                }
            }
        }

        /** @var array<string, string> $peerThreadByAccountSubject */
        $peerThreadByAccountSubject = [];
        foreach ($subjectsByAccount as $accountId => $subjects) {
            /** @var list<MailMessage> $peers */
            $peers = $this->em->createQueryBuilder()
                ->select('m')
                ->from(MailMessage::class, 'm')
                ->where('m.accountId = :aid')
                ->andWhere('m.threadSubject IN (:subjects)')
                ->andWhere('m.threadId IS NOT NULL')
                ->setParameter('aid', $accountId)
                ->setParameter('subjects', array_keys($subjects))
                ->orderBy('m.dateAt', 'ASC')
                ->getQuery()->getResult();
            foreach ($peers as $peer) {
                $ts = (string) $peer->threadSubject();
                $tid = $peer->threadId();
                $key = $accountId . "\0" . $ts;
                if ($ts !== '' && $tid !== null && $tid !== '' && !isset($peerThreadByAccountSubject[$key])) {
                    $peerThreadByAccountSubject[$key] = $tid;
                }
            }
        }

        foreach ($pending as $message) {
            $accountId = $message->accountId();
            $parentThreadId = null;
            $irt = $message->inReplyTo();
            if ($irt !== null && $irt !== '') {
                $parentThreadId = $parentThreadByAccountMsgId[$accountId . "\0" . $irt] ?? null;
            }
            $threadSubject = MailThreadResolver::normalizeSubject($message->subject());
            if ($parentThreadId === null && $threadSubject !== '') {
                $parentThreadId = $peerThreadByAccountSubject[$accountId . "\0" . $threadSubject] ?? null;
            }
            $threadId = MailThreadResolver::resolveThreadId(
                $message->messageId(),
                $message->inReplyTo(),
                MailThreadResolver::parseIdList($message->referencesRaw()),
                $message->subject(),
                $accountId,
                $parentThreadId,
            );
            $message->applyThread(
                $message->inReplyTo(),
                $message->referencesRaw(),
                $threadId,
                $threadSubject !== '' ? mb_substr($threadSubject, 0, 500) : null,
            );
            if ($threadSubject !== '') {
                $peerThreadByAccountSubject[$accountId . "\0" . $threadSubject] ??= $threadId;
            }
            if ($message->messageId()) {
                $parentThreadByAccountMsgId[$accountId . "\0" . $message->messageId()] ??= $threadId;
            }
        }

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            error_log('[emailing.ensureThreadIds] ' . $e->getMessage());
            // Neblokovat schránku kvůli jednomu dlouhému thread_id / message-id.
            foreach ($pending as $message) {
                $this->em->detach($message);
            }
        }
    }

    /**
     * @param list<MailMessage> $messages
     * @return list<array<string, mixed>>
     */
    private function presentThreads(array $messages): array
    {
        /** @var array<string, list<MailMessage>> $groups */
        $groups = [];
        foreach ($messages as $message) {
            $key = $message->threadId() ?: ('id:' . $message->id());
            $groups[$key][] = $message;
        }

        $latestIds = [];
        $rows = [];
        foreach ($groups as $threadId => $members) {
            usort($members, static function (MailMessage $a, MailMessage $b): int {
                $da = $a->dateAt()?->getTimestamp() ?? 0;
                $db = $b->dateAt()?->getTimestamp() ?? 0;

                return $db <=> $da;
            });
            $latest = $members[0];
            $latestIds[] = $latest->id();
            $presented = $this->presentMessage($latest);
            $presented['thread_id'] = $threadId;
            $presented['thread_count'] = count($members);
            $presented['thread_subject'] = $latest->threadSubject()
                ?: MailThreadResolver::normalizeSubject($latest->subject())
                ?: $presented['subject'];
            $presented['subject'] = $presented['thread_subject'];
            $presented['thread_unread'] = count(array_filter($members, static fn (MailMessage $m): bool => !$m->isSeen()));
            $presented['thread_items'] = array_map(
                fn (MailMessage $m): array => $this->presentMessage($m),
                array_reverse($members),
            );
            $presented['attachment_previews'] = [];
            $rows[] = $presented;
        }

        $previewsByMessage = $this->attachmentPreviewsByMessageId($latestIds);
        $snippetsByMessage = $this->bodySnippetsByMessageId($latestIds);
        foreach ($rows as &$row) {
            $row['attachment_previews'] = $previewsByMessage[(string) $row['id']] ?? [];
            if ($row['attachment_previews'] !== []) {
                $row['has_attachments'] = true;
            }
            if (($row['snippet'] ?? '') === '' && isset($snippetsByMessage[(string) $row['id']])) {
                $row['snippet'] = $snippetsByMessage[(string) $row['id']];
            }
        }
        unset($row);

        usort($rows, static function (array $a, array $b): int {
            $da = (string) ($a['date_sort'] ?? '');
            $db = (string) ($b['date_sort'] ?? '');

            return $db <=> $da;
        });

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{key: string, label: string, rows: list<array<string, mixed>>}>
     */
    private function groupRowsByDate(array $rows): array
    {
        $order = ['today', 'yesterday', 'this_week', 'this_month', 'older'];
        $labels = [
            'today' => $this->translator->trans('emailing.mailbox.group_today'),
            'yesterday' => $this->translator->trans('emailing.mailbox.group_yesterday'),
            'this_week' => $this->translator->trans('emailing.mailbox.group_this_week'),
            'this_month' => $this->translator->trans('emailing.mailbox.group_this_month'),
            'older' => $this->translator->trans('emailing.mailbox.group_older'),
        ];
        /** @var array<string, list<array<string, mixed>>> $buckets */
        $buckets = array_fill_keys($order, []);
        foreach ($rows as $row) {
            $key = $this->dateGroupKey($row['date_sort'] ?? null);
            $buckets[$key][] = $row;
        }

        $groups = [];
        foreach ($order as $key) {
            if ($buckets[$key] === []) {
                continue;
            }
            $groups[] = [
                'key' => $key,
                'label' => $labels[$key],
                'rows' => $buckets[$key],
            ];
        }

        return $groups;
    }

    private function dateGroupKey(mixed $dateSort): string
    {
        if (!is_string($dateSort) || $dateSort === '') {
            return 'older';
        }
        try {
            $date = new \DateTimeImmutable($dateSort);
        } catch (\Exception) {
            return 'older';
        }

        $today = (new \DateTimeImmutable('today'));
        $day = $date->setTime(0, 0);
        if ($day == $today) {
            return 'today';
        }
        if ($day == $today->modify('-1 day')) {
            return 'yesterday';
        }
        $weekStart = $today->modify('monday this week')->setTime(0, 0);
        if ($day >= $weekStart) {
            return 'this_week';
        }
        $monthStart = $today->modify('first day of this month')->setTime(0, 0);
        if ($day >= $monthStart) {
            return 'this_month';
        }

        return 'older';
    }

    /**
     * @param list<string> $messageIds
     * @return array<string, list<array{id: string, filename: string, mime: string, is_image: bool}>>
     */
    private function attachmentPreviewsByMessageId(array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_filter($messageIds)));
        if ($messageIds === []) {
            return [];
        }

        $attachments = $this->em->createQueryBuilder()
            ->select('a')
            ->from(MailAttachment::class, 'a')
            ->where('a.messageId IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        $byMessage = [];
        foreach ($attachments as $attachment) {
            if (!$attachment->isDownloadable()) {
                continue;
            }
            $mid = $attachment->messageId();
            if (count($byMessage[$mid] ?? []) >= 4) {
                continue;
            }
            $mime = strtolower($attachment->mime());
            $filename = $attachment->filename();
            $kind = $this->attachmentKind($filename, $mime);
            $byMessage[$mid][] = [
                'id' => $attachment->id(),
                'filename' => $filename,
                'mime' => $mime,
                'is_image' => $kind === 'image',
                'kind' => $kind,
                'badge' => $this->attachmentBadge($kind),
            ];
        }

        return $byMessage;
    }

    /**
     * Outlook-like file type for chip coloring.
     *
     * @return 'pdf'|'word'|'excel'|'powerpoint'|'image'|'archive'|'text'|'audio'|'video'|'mail'|'other'
     */
    private function attachmentKind(string $filename, string $mime): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = strtolower($mime);

        return match (true) {
            $ext === 'pdf' || str_contains($mime, 'pdf') => 'pdf',
            in_array($ext, ['doc', 'docx', 'rtf', 'odt'], true)
                || str_contains($mime, 'msword')
                || str_contains($mime, 'wordprocessingml') => 'word',
            in_array($ext, ['xls', 'xlsx', 'csv', 'ods'], true)
                || str_contains($mime, 'excel')
                || str_contains($mime, 'spreadsheetml') => 'excel',
            in_array($ext, ['ppt', 'pptx', 'odp'], true)
                || str_contains($mime, 'powerpoint')
                || str_contains($mime, 'presentationml') => 'powerpoint',
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'heic'], true)
                || str_starts_with($mime, 'image/') => 'image',
            in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar', 'bz2'], true)
                || str_contains($mime, 'zip')
                || str_contains($mime, 'compressed')
                || str_contains($mime, 'x-tar') => 'archive',
            in_array($ext, ['txt', 'md', 'log', 'json', 'xml', 'html', 'htm'], true)
                || str_starts_with($mime, 'text/') => 'text',
            in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'flac'], true)
                || str_starts_with($mime, 'audio/') => 'audio',
            in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'], true)
                || str_starts_with($mime, 'video/') => 'video',
            in_array($ext, ['eml', 'msg'], true)
                || str_contains($mime, 'message/') => 'mail',
            default => 'other',
        };
    }

    private function attachmentBadge(string $kind): string
    {
        return match ($kind) {
            'pdf' => 'PDF',
            'word' => 'W',
            'excel' => 'X',
            'powerpoint' => 'P',
            'image' => 'IMG',
            'archive' => 'ZIP',
            'text' => 'TXT',
            'audio' => '♪',
            'video' => '▶',
            'mail' => '@',
            default => 'FILE',
        };
    }

    /**
     * @param list<string> $messageIds
     * @return array<string, string>
     */
    private function bodySnippetsByMessageId(array $messageIds): array
    {
        $messageIds = array_values(array_unique(array_filter($messageIds)));
        if ($messageIds === []) {
            return [];
        }

        /** @var list<MailBody> $bodies */
        $bodies = $this->em->createQueryBuilder()
            ->select('b')
            ->from(MailBody::class, 'b')
            ->where('b.messageId IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($bodies as $body) {
            $text = trim((string) ($body->textPlain() ?: ''));
            if ($text === '') {
                $html = (string) ($body->textHtml() ?: '');
                if ($html !== '') {
                    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            if ($text === '') {
                continue;
            }
            $out[$body->messageId()] = mb_substr($text, 0, 160);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function presentMessage(MailMessage $m): array
    {
        $from = MimeHeaderDecoder::decode($m->fromName()) ?: (string) $m->fromAddress();
        $subject = MimeHeaderDecoder::decode($m->subject());
        $subjectLabel = $subject !== '' ? $subject : $this->translator->trans('emailing.mailbox.no_subject');
        $snippet = MimeHeaderDecoder::decode($m->snippet());
        if ($snippet === '' || $snippet === $subject || $snippet === $subjectLabel) {
            $snippet = '';
        }
        $initials = $this->initials($from !== '' ? $from : '?');
        $replyMessageId = $m->messageId();
        $references = MailThreadResolver::buildReferencesHeader($m->referencesRaw(), $replyMessageId);
        $isReply = ($m->inReplyTo() !== null && $m->inReplyTo() !== '')
            || ($m->referencesRaw() !== null && trim($m->referencesRaw()) !== '');

        return [
            'id' => $m->id(),
            'from' => $from !== '' ? $from : '—',
            'from_address' => $m->fromAddress(),
            'subject' => $subjectLabel,
            'snippet' => $snippet,
            'date' => $this->formatMailDate($m->dateAt()),
            'date_full' => $m->dateAt()?->format('d.m.Y H:i'),
            'date_sort' => $m->dateAt()?->format('Y-m-d H:i:s') ?? '',
            'unread' => !$m->isSeen(),
            'flagged' => $m->isFlagged(),
            'pinned' => $m->isPinned(),
            'keywords' => $m->keywords(),
            'has_attachments' => $m->hasAttachments(),
            'is_reply' => $isReply,
            'attachment_previews' => [],
            'initials' => $initials,
            'avatar_url' => $this->bimi->logoUrlForEmail($m->fromAddress()),
            'thread_id' => $m->threadId(),
            'thread_subject' => $m->threadSubject() ?: MailThreadResolver::normalizeSubject($m->subject()),
            'message_id' => $replyMessageId,
            'in_reply_to' => $m->inReplyTo(),
            'references' => $references,
        ];
    }

    private function initials(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return '?';
        }
        $parts = preg_split('/\s+/', $name) ?: [$name];
        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }

    private function formatMailDate(?\DateTimeImmutable $date): string
    {
        if ($date === null) {
            return '';
        }
        $now = new \DateTimeImmutable('now');
        $today = $now->setTime(0, 0);
        $day = $date->setTime(0, 0);
        $daysAgo = (int) $day->diff($today)->format('%a');
        $dayNames = ['Ne', 'Po', 'Út', 'St', 'Čt', 'Pá', 'So'];

        if ($daysAgo <= 6) {
            return $dayNames[(int) $date->format('w')] . ' ' . $date->format('H:i');
        }

        return $date->format('d.m.Y');
    }

    /**
     * @param list<MailFolder> $folders
     */
    private function sortMailFolders(array &$folders): void
    {
        usort($folders, static function (MailFolder $a, MailFolder $b): int {
            if ($a->isFavorite() !== $b->isFavorite()) {
                return $a->isFavorite() ? -1 : 1;
            }
            if ($a->sortOrder() !== $b->sortOrder()) {
                return $a->sortOrder() <=> $b->sortOrder();
            }
            $order = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'junk' => 3, 'trash' => 4, 'other' => 5];
            $oa = $order[$a->role()] ?? 9;
            $ob = $order[$b->role()] ?? 9;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcasecmp($a->name(), $b->name());
        });
    }

    private function wrapEmailHtmlDocument(string $html): string
    {
        $trimmed = ltrim($html);
        if ($trimmed !== '' && (preg_match('/^<!DOCTYPE/i', $trimmed) === 1 || preg_match('/<html[\s>]/i', $trimmed) === 1)) {
            return $html;
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<base target="_blank" rel="noopener">'
            . '</head><body>' . $html . '</body></html>';
    }

    /**
     * @return list<string>
     */
    private function parseTagFilters(Request $request): array
    {
        $raw = $request->query->all()['tag'] ?? [];
        if (is_string($raw) || is_numeric($raw)) {
            $raw = [$raw];
        }
        if (!is_array($raw)) {
            $raw = [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $keyword = ImapKeyword::normalize((string) $value);
            if ($keyword === null || $keyword === ImapKeyword::PINNED) {
                continue;
            }
            $out[$keyword] = $keyword;
        }

        return array_values($out);
    }

    /**
     * @param list<string> $tags
     * @param array{from?: string, unread?: bool, has_attachment?: bool} $filters
     */
    private function mailboxListQuery(?string $accountId, string $q, array $tags, array $filters = []): string
    {
        $parts = [];
        if ($accountId !== null && $accountId !== '') {
            $parts[] = 'account=' . rawurlencode($accountId);
        }
        if ($q !== '') {
            $parts[] = 'q=' . rawurlencode($q);
        }
        foreach ($tags as $tag) {
            $parts[] = 'tag%5B%5D=' . rawurlencode($tag);
        }
        $from = trim((string) ($filters['from'] ?? ''));
        if ($from !== '') {
            $parts[] = 'from=' . rawurlencode($from);
        }
        if (!empty($filters['unread'])) {
            $parts[] = 'unread=1';
        }
        if (!empty($filters['has_attachment'])) {
            $parts[] = 'has_attachment=1';
        }

        return implode('&', $parts);
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
