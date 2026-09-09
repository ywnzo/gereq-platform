<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Modules\Emailing\Domain\Events\MailboxFolderReconciled;
use Modules\Emailing\Domain\Events\MailboxPolled;
use Modules\Emailing\Domain\MailAccount;
use Modules\Emailing\Domain\MailAttachment;
use Modules\Emailing\Domain\MailBody;
use Modules\Emailing\Domain\MailFolder;
use Modules\Emailing\Domain\MailLabel;
use Modules\Emailing\Domain\MailMessage;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

final class MailboxSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CredentialVault $vault,
        private readonly EventBus $events,
    ) {
    }

    public function syncAccount(MailAccount $account, int $batchLimit = 100): void
    {
        if ($account->incomingHost() === '') {
            return;
        }

        $client = $this->connect($account);
        try {
            $remoteFolders = $client->getFolders(false);
            foreach ($remoteFolders as $remote) {
                /** @var Folder $remote */
                $this->ensureLocalFolder($account, $remote);
            }
            $this->em->flush();

            /** @var list<MailFolder> $folders */
            $folders = $this->em->getRepository(MailFolder::class)->findBy(['accountId' => $account->id()]);
            foreach ($folders as $folder) {
                $this->syncFolder($account, $folder, $batchLimit);
            }

            $account->markSynced();
            $this->em->flush();
            $this->relinkThreads($account->id());
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    public function syncFolder(MailAccount $account, MailFolder $folder, int $batchLimit = 100): void
    {
        $client = $this->connect($account);
        try {
            $this->syncFolderWithClient($account, $folder, $client, $batchLimit);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Lehký poll: IMAP STATUS (MESSAGES/UNSEEN) vs lokální counts.
     * Při rozdílu reconcile složky podle UID (přidání / smazání / změna flagů).
     *
     * @return array{
     *   folders: list<array<string, mixed>>,
     *   changed_folder_ids: list<string>,
     *   reconciled: list<array<string, mixed>>,
     *   synced: bool
     * }
     */
    public function pollAndReconcile(MailAccount $account): array
    {
        if ($account->incomingHost() === '') {
            $empty = [
                'folders' => [],
                'changed_folder_ids' => [],
                'reconciled' => [],
                'synced' => false,
            ];
            $this->events->dispatch(MailboxPolled::make($account->id(), $empty));

            return $empty;
        }

        $client = $this->connect($account);
        try {
            /** @var list<MailFolder> $folders */
            $folders = $this->em->getRepository(MailFolder::class)->findBy(['accountId' => $account->id()]);
            $folderRows = [];
            $changedIds = [];

            foreach ($folders as $folder) {
                $remote = $client->getFolderByPath($folder->remotePath());
                $remoteTotal = null;
                $remoteUnread = null;
                if ($remote !== null) {
                    try {
                        $status = $remote->status();
                        $remoteTotal = isset($status['messages']) ? (int) $status['messages'] : null;
                        $remoteUnread = isset($status['unseen']) ? (int) $status['unseen'] : null;
                    } catch (\Throwable) {
                        $remoteTotal = null;
                        $remoteUnread = null;
                    }
                }

                $localTotal = $folder->totalCount();
                $localUnread = $folder->unreadCount();
                $changed = $remoteTotal !== null && (
                    $remoteTotal !== $localTotal
                    || ($remoteUnread !== null && $remoteUnread !== $localUnread)
                );

                $row = [
                    'id' => $folder->id(),
                    'name' => $folder->name(),
                    'role' => $folder->role(),
                    'local_total' => $localTotal,
                    'local_unread' => $localUnread,
                    'remote_total' => $remoteTotal,
                    'remote_unread' => $remoteUnread,
                    'changed' => $changed,
                ];
                $folderRows[] = $row;
                if ($changed) {
                    $changedIds[] = $folder->id();
                }
            }

            $this->events->dispatch(MailboxPolled::make($account->id(), [
                'folders' => $folderRows,
                'changed_folder_ids' => $changedIds,
                'changed_count' => count($changedIds),
            ]));

            $reconciled = [];
            foreach ($folders as $folder) {
                if (!in_array($folder->id(), $changedIds, true)) {
                    continue;
                }
                $delta = $this->reconcileFolderWithClient($account, $folder, $client);
                $reconciled[] = $delta;
                $this->events->dispatch(MailboxFolderReconciled::make($folder->id(), $delta));
            }

            if ($reconciled !== []) {
                $account->markSynced();
                $this->em->flush();
                $this->relinkThreads($account->id());
            }

            // Po reconcile znovu načíst aktuální counts do odpovědi.
            $freshFolders = [];
            foreach ($folders as $folder) {
                $fresh = $this->em->find(MailFolder::class, $folder->id()) ?? $folder;
                $freshFolders[] = [
                    'id' => $fresh->id(),
                    'name' => $fresh->name(),
                    'role' => $fresh->role(),
                    'local_total' => $fresh->totalCount(),
                    'local_unread' => $fresh->unreadCount(),
                    'remote_total' => null,
                    'remote_unread' => null,
                    'changed' => in_array($fresh->id(), $changedIds, true),
                    'unread_count' => $fresh->unreadCount(),
                    'total_count' => $fresh->totalCount(),
                ];
            }

            return [
                'folders' => $freshFolders,
                'changed_folder_ids' => $changedIds,
                'reconciled' => $reconciled,
                'synced' => $reconciled !== [],
            ];
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return array{
     *   folder_id: string,
     *   added: list<string>,
     *   removed: list<string>,
     *   updated: list<string>,
     *   added_count: int,
     *   removed_count: int,
     *   updated_count: int
     * }
     */
    private function reconcileFolderWithClient(MailAccount $account, MailFolder $folder, Client $client): array
    {
        $remote = $client->getFolderByPath($folder->remotePath());
        if ($remote === null) {
            return [
                'folder_id' => $folder->id(),
                'added' => [],
                'removed' => [],
                'updated' => [],
                'added_count' => 0,
                'removed_count' => 0,
                'updated_count' => 0,
            ];
        }

        $status = [];
        try {
            $status = $remote->status();
        } catch (\Throwable) {
        }
        $uidValidity = isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : null;
        if ($uidValidity !== null && $folder->uidValidity() !== null && $uidValidity !== $folder->uidValidity()) {
            $this->purgeFolderMessages($folder->id());
            $folder->setSyncCursor($uidValidity, null);
            $this->em->flush();
        } elseif ($uidValidity !== null && $folder->uidValidity() === null) {
            $folder->setSyncCursor($uidValidity, $folder->lastUid());
        }

        $messages = $remote->messages()->leaveUnread()->setFetchBody(false)->setFetchFlags(true)->all()->get();
        /** @var array<int, Message> $remoteByUid */
        $remoteByUid = [];
        $maxUid = 0;
        foreach ($messages as $msg) {
            /** @var Message $msg */
            $uid = (int) $msg->getUid();
            $remoteByUid[$uid] = $msg;
            $maxUid = max($maxUid, $uid);
        }

        /** @var list<MailMessage> $locals */
        $locals = $this->em->getRepository(MailMessage::class)->findBy(['folderId' => $folder->id()]);
        /** @var array<int, MailMessage> $localByUid */
        $localByUid = [];
        foreach ($locals as $local) {
            $localByUid[$local->uid()] = $local;
        }

        $removed = [];
        $removeIds = [];
        foreach ($localByUid as $uid => $local) {
            if (isset($remoteByUid[$uid])) {
                continue;
            }
            $removed[] = $local->id();
            $removeIds[] = $local->id();
        }
        if ($removeIds !== []) {
            $this->purgeMessagesByIds($removeIds);
        }

        $added = [];
        $updated = [];
        foreach ($remoteByUid as $uid => $msg) {
            $existing = $localByUid[$uid] ?? null;
            if (!$existing instanceof MailMessage) {
                $created = $this->upsertMessageIndex($account, $folder, $msg);
                $added[] = $created->id();
                continue;
            }

            $flags = $msg->getFlags();
            $isSeen = (bool) ($flags && ($flags->get('seen') || $flags->get('Seen')));
            $isFlagged = (bool) ($flags && ($flags->get('flagged') || $flags->get('Flagged')));
            $changed = false;
            if ($existing->isSeen() !== $isSeen) {
                $existing->setSeen($isSeen);
                $changed = true;
            }
            if ($existing->isFlagged() !== $isFlagged) {
                $existing->setFlagged($isFlagged);
                $changed = true;
            }
            if ($changed) {
                $updated[] = $existing->id();
            }
        }

        if ($maxUid > 0) {
            $folder->setSyncCursor($folder->uidValidity() ?? $uidValidity, $maxUid);
        }

        $this->em->flush();
        $this->refreshFolderCounts($folder);

        if ($added !== []) {
            $this->processNewMessageRules($account, $folder, $added);
        }

        return [
            'folder_id' => $folder->id(),
            'added' => $added,
            'removed' => $removed,
            'updated' => $updated,
            'added_count' => count($added),
            'removed_count' => count($removed),
            'updated_count' => count($updated),
        ];
    }

    public function refreshFolderCounts(MailFolder $folder): void
    {
        $unread = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(MailMessage::class, 'm')
            ->where('m.folderId = :fid')->andWhere('m.isSeen = false')
            ->setParameter('fid', $folder->id())
            ->getQuery()->getSingleScalarResult();
        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(MailMessage::class, 'm')
            ->where('m.folderId = :fid')
            ->setParameter('fid', $folder->id())
            ->getQuery()->getSingleScalarResult();
        $folder->setCounts($unread, $total);
        $this->em->flush();
    }

    public function purgeLocalMessage(string $messageId): void
    {
        $this->purgeMessagesByIds([$messageId]);
    }

    public function purgeFolderMessagesPublic(string $folderId): void
    {
        $this->purgeFolderMessages($folderId);
    }

    public function indexRemoteMessage(MailAccount $account, MailFolder $folder, Message $msg): MailMessage
    {
        $row = $this->upsertMessageIndex($account, $folder, $msg);
        $this->em->flush();
        $this->refreshFolderCounts($folder);

        return $row;
    }

    private function syncFolderWithClient(MailAccount $account, MailFolder $folder, Client $client, int $batchLimit = 100): void
    {
        $remote = $client->getFolderByPath($folder->remotePath());
        if ($remote === null) {
            return;
        }

        $status = $remote->status();
        $uidValidity = isset($status['uidvalidity']) ? (int) $status['uidvalidity'] : null;
        if ($uidValidity !== null && $folder->uidValidity() !== null && $uidValidity !== $folder->uidValidity()) {
            $this->purgeFolderMessages($folder->id());
            $folder->setSyncCursor($uidValidity, null);
        } elseif ($uidValidity !== null && $folder->uidValidity() === null) {
            $folder->setSyncCursor($uidValidity, $folder->lastUid());
        }

        $lastUid = $folder->lastUid();
        $query = $remote->messages()->leaveUnread()->setFetchBody(false)->setFetchFlags(true);

        // Dovecot (vas-hosting) odmítá prázdný UID SEARCH — vždy ALL nebo filtr podle UID.
        if ($lastUid !== null && $lastUid > 0) {
            $messages = $query->getByUidGreater($lastUid);
        } else {
            $messages = $query->all()->limit($batchLimit)->get();
        }

        $count = 0;
        $maxUid = $lastUid ?? 0;
        $added = [];
        foreach ($messages as $msg) {
            /** @var Message $msg */
            $uid = (int) $msg->getUid();
            if ($lastUid !== null && $uid <= $lastUid) {
                continue;
            }
            $maxUid = max($maxUid, $uid);
            $existing = $this->em->getRepository(MailMessage::class)->findOneBy([
                'folderId' => $folder->id(),
                'uid' => $uid,
            ]);
            $row = $this->upsertMessageIndex($account, $folder, $msg);
            if (!$existing instanceof MailMessage) {
                $added[] = $row->id();
            }
            if (++$count >= $batchLimit) {
                break;
            }
        }

        if ($maxUid > 0) {
            $folder->setSyncCursor($folder->uidValidity() ?? $uidValidity, $maxUid);
        }

        $this->em->flush();
        $this->refreshFolderCounts($folder);
        if ($added !== []) {
            $this->processNewMessageRules($account, $folder, $added);
        }
    }

    /**
     * @param list<string> $messageIds
     */
    private function processNewMessageRules(MailAccount $account, MailFolder $folder, array $messageIds): void
    {
        try {
            /** @var MailRuleEngine $engine */
            $engine = gereq()->make(MailRuleEngine::class);
            $engine->processNewMessages($account, $folder, $messageIds);
        } catch (\Throwable $e) {
            error_log('[emailing.rules] ' . $e->getMessage());
        }
    }

    /** @param list<string> $ids */
    private function purgeMessagesByIds(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return;
        }

        $this->em->createQueryBuilder()->delete(MailBody::class, 'b')
            ->where('b.messageId IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(MailAttachment::class, 'a')
            ->where('a.messageId IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(MailMessage::class, 'm')
            ->where('m.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->execute();
    }

    public function fetchBody(MailAccount $account, MailMessage $message, bool $force = false): MailBody
    {
        $existing = $this->em->getRepository(MailBody::class)->findOneBy(['messageId' => $message->id()]);
        if ($existing instanceof MailBody && !$force) {
            $html = (string) ($existing->textHtml() ?? '');
            $needsCidFix = HtmlCidRewriter::containsCid($html);
            $needsAttFix = false;
            if ($message->hasAttachments()) {
                $storedAny = (int) $this->em->createQueryBuilder()
                    ->select('COUNT(a.id)')
                    ->from(MailAttachment::class, 'a')
                    ->where('a.messageId = :id')
                    ->setParameter('id', $message->id())
                    ->getQuery()
                    ->getSingleScalarResult();
                // Přílohy ještě nebyly staženy (tělo ano) — doplnit.
                $needsAttFix = $storedAny === 0;
            }
            if (!$needsCidFix && !$needsAttFix) {
                return $existing;
            }
            $force = true;
        }

        if ($force) {
            $this->purgeMessageBodies($message->id());
            $message = $this->em->find(MailMessage::class, $message->id()) ?? $message;
        }

        $folder = $this->em->find(MailFolder::class, $message->folderId());
        if (!$folder instanceof MailFolder) {
            throw new \RuntimeException('Složka zprávy neexistuje.');
        }

        $client = $this->connect($account);
        try {
            $remote = $client->getFolderByPath($folder->remotePath());
            if ($remote === null) {
                throw new \RuntimeException('Vzdálená složka nenalezena.');
            }
            $msg = $remote->messages()->getMessageByUid($message->uid());
            if (!$msg instanceof Message) {
                throw new \RuntimeException('Zpráva na serveru nenalezena.');
            }

            $html = (string) ($msg->getHTMLBody() ?: '');
            $text = (string) ($msg->getTextBody() ?: '');
            if ($html === '' && $text === '') {
                $text = (string) $msg->getRawBody();
            }

            $cidMap = [];
            foreach ($msg->getAttachments() as $attachment) {
                $rawName = (string) ($attachment->getName() ?: $attachment->getFilename() ?: '');
                $filename = MimeHeaderDecoder::decode($rawName !== '' ? $rawName : 'attachment.bin');
                if ($filename === '') {
                    $filename = 'attachment.bin';
                }
                $mime = (string) ($attachment->getMimeType() ?: $attachment->getContentType() ?: 'application/octet-stream');
                $content = (string) $attachment->getContent();
                $cid = trim((string) ($attachment->getId() ?: ''), "<> \t\"'");
                $disposition = strtolower((string) ($attachment->getDisposition() ?: ''));
                $isImage = str_starts_with(strtolower($mime), 'image/');
                // Jen skutečné inline obrázky (CID / disposition=inline) — PDF apod. vždy ke stažení.
                if ($disposition === 'attachment') {
                    $inline = false;
                } elseif ($disposition === 'inline') {
                    $inline = $isImage;
                } else {
                    $inline = $cid !== '' && $isImage;
                }
                $stored = $this->storeBlob($filename, $mime, $content);
                $this->em->persist(new MailAttachment(
                    $message->id(),
                    mb_substr($filename, 0, 255),
                    mb_substr($mime, 0, 120),
                    strlen($content),
                    $stored,
                    $cid !== '' ? $cid : null,
                    $inline,
                ));
                if ($cid !== '' && $content !== '' && $isImage) {
                    $safeMime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime) ? $mime : 'application/octet-stream';
                    $cidMap[$cid] = 'data:' . $safeMime . ';base64,' . base64_encode($content);
                }
            }

            if ($html !== '') {
                $html = HtmlCidRewriter::rewrite($html, $cidMap);
            }

            $body = new MailBody($message->id(), $text !== '' ? $text : null, $html !== '' ? $html : null);
            $this->em->persist($body);
            $message->markBodyCached();
            $this->em->flush();

            return $body;
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    private function purgeMessageBodies(string $messageId): void
    {
        $root = base_path('storage/uploads');

        /** @var list<MailAttachment> $oldAttachments */
        $oldAttachments = $this->em->getRepository(MailAttachment::class)->findBy(['messageId' => $messageId]);
        foreach ($oldAttachments as $old) {
            $absolute = $root . '/' . ltrim($old->path(), '/');
            if (is_file($absolute)) {
                @unlink($absolute);
            }
            $this->em->remove($old);
        }

        /** @var list<MailBody> $oldBodies */
        $oldBodies = $this->em->getRepository(MailBody::class)->findBy(['messageId' => $messageId]);
        foreach ($oldBodies as $oldBody) {
            $this->em->remove($oldBody);
        }

        $message = $this->em->find(MailMessage::class, $messageId);
        if ($message instanceof MailMessage) {
            $message->clearBodyCache();
        }

        $this->em->flush();
    }

    public function connect(MailAccount $account): Client
    {
        $cm = new ClientManager([]);
        $password = $this->vault->decrypt($account->incomingPasswordEnc());
        $encryption = match ($account->incomingEncryption()) {
            'ssl' => 'ssl',
            'tls' => 'tls',
            default => false,
        };

        $client = $cm->make([
            'host' => MailHostNormalizer::normalize($account->incomingHost()),
            'port' => $account->incomingPort(),
            'encryption' => $encryption,
            'validate_cert' => true,
            'username' => $account->incomingUsername() !== '' ? $account->incomingUsername() : $account->email(),
            'password' => $password,
            'protocol' => $account->incomingProtocol() === 'pop3' ? 'pop3' : 'imap',
        ]);
        $client->connect();

        return $client;
    }

    private function ensureLocalFolder(MailAccount $account, Folder $remote): MailFolder
    {
        $path = (string) $remote->path;
        $name = (string) ($remote->name ?: $path);
        $existing = $this->em->getRepository(MailFolder::class)->findOneBy([
            'accountId' => $account->id(),
            'remotePath' => $path,
        ]);
        if ($existing instanceof MailFolder) {
            $existing->setName($name);
            $existing->setRole($this->guessRole($path, $name));

            return $existing;
        }

        $folder = new MailFolder($account->id(), $name, $path, $this->guessRole($path, $name));
        $this->em->persist($folder);

        return $folder;
    }

    private function upsertMessageIndex(MailAccount $account, MailFolder $folder, Message $msg): MailMessage
    {
        $uid = (int) $msg->getUid();
        $existing = $this->em->getRepository(MailMessage::class)->findOneBy([
            'folderId' => $folder->id(),
            'uid' => $uid,
        ]);
        $row = $existing instanceof MailMessage
            ? $existing
            : new MailMessage($account->id(), $folder->id(), $uid);

        if (!$existing instanceof MailMessage) {
            $this->em->persist($row);
        }

        $from = $msg->getFrom();
        $fromAddr = null;
        $fromName = null;
        if ($from && $from->count() > 0) {
            $first = $from->first();
            $fromAddr = (string) ($first->mail ?? '');
            $fromName = (string) ($first->personal ?? '');
        }

        $toParts = [];
        $to = $msg->getTo();
        if ($to) {
            foreach ($to as $addr) {
                $toParts[] = (string) ($addr->mail ?? '');
            }
        }

        $subject = MimeHeaderDecoder::decode((string) ($msg->getSubject() ?: ''));
        $fromName = MimeHeaderDecoder::decode($fromName ?? '');
        $date = null;
        try {
            $dateAttr = $msg->getDate();
            if ($dateAttr) {
                $date = new \DateTimeImmutable((string) $dateAttr);
            }
        } catch (\Throwable) {
            $date = null;
        }

        $flags = $msg->getFlags();
        $isSeen = $flags && ($flags->get('seen') || $flags->get('Seen'));
        $isFlagged = $flags && ($flags->get('flagged') || $flags->get('Flagged'));
        $flagNames = [];
        if ($flags) {
            foreach ($flags as $flagName => $flagValue) {
                if ($flagValue) {
                    $flagNames[] = is_string($flagName) ? $flagName : (string) $flagValue;
                }
            }
            if (method_exists($flags, 'all')) {
                foreach ($flags->all() as $name => $value) {
                    if ($value) {
                        $flagNames[] = (string) $name;
                    }
                }
            }
        }
        $keywords = ImapKeyword::extractFromFlags($flagNames);

        $messageId = MailThreadResolver::normalizeId($this->attributeToString($msg->getMessageId()));
        $inReplyTo = MailThreadResolver::normalizeId($this->attributeToString($msg->getInReplyTo()));
        $references = $this->attributeToIdList($msg->getReferences());
        $referencesRaw = $references !== []
            ? implode(' ', array_map(static fn (string $id): string => '<' . $id . '>', $references))
            : null;

        $parentThreadId = null;
        if ($inReplyTo !== null) {
            $parent = $this->em->getRepository(MailMessage::class)->findOneBy([
                'accountId' => $account->id(),
                'messageId' => $inReplyTo,
            ]);
            if ($parent instanceof MailMessage && $parent->threadId()) {
                $parentThreadId = $parent->threadId();
            }
        }

        $threadSubject = MailThreadResolver::normalizeSubject($subject !== '' ? $subject : null);
        $threadId = MailThreadResolver::resolveThreadId(
            $messageId,
            $inReplyTo,
            $references,
            $subject !== '' ? $subject : null,
            $account->id(),
            $parentThreadId,
        );

        $row->applyHeaders(
            messageId: $messageId,
            fromAddress: $fromAddr ?: null,
            fromName: $fromName !== '' ? $fromName : null,
            toAddresses: $toParts !== [] ? implode(', ', $toParts) : null,
            subject: $subject !== '' ? mb_substr($subject, 0, 500) : null,
            snippet: $subject !== '' ? mb_substr($subject, 0, 180) : null,
            dateAt: $date,
            isSeen: (bool) $isSeen,
            isFlagged: (bool) $isFlagged,
            hasAttachments: (bool) $msg->hasAttachments(),
            sizeBytes: null,
        );
        $row->setKeywords($keywords);
        foreach ($keywords as $kw) {
            if ($kw === ImapKeyword::PINNED) {
                continue;
            }
            $existingLabel = $this->em->getRepository(MailLabel::class)->findOneBy([
                'accountId' => $account->id(),
                'keyword' => $kw,
            ]);
            if (!$existingLabel instanceof MailLabel) {
                $this->em->persist(new MailLabel($account->id(), $kw, $kw));
            }
        }
        $row->applyThread(
            $inReplyTo,
            $referencesRaw,
            $threadId,
            $threadSubject !== '' ? mb_substr($threadSubject, 0, 500) : null,
        );

        return $row;
    }

    /**
     * Sjednotí thread_id podle In-Reply-To → Message-ID v rámci účtu.
     */
    public function relinkThreads(string $accountId): void
    {
        /** @var list<MailMessage> $withReply */
        $withReply = $this->em->createQueryBuilder()
            ->select('m')
            ->from(MailMessage::class, 'm')
            ->where('m.accountId = :aid')
            ->andWhere('m.inReplyTo IS NOT NULL')
            ->setParameter('aid', $accountId)
            ->getQuery()->getResult();

        $dirty = false;
        foreach ($withReply as $msg) {
            $parentId = $msg->inReplyTo();
            if ($parentId === null || $parentId === '') {
                continue;
            }
            $parent = $this->em->getRepository(MailMessage::class)->findOneBy([
                'accountId' => $accountId,
                'messageId' => $parentId,
            ]);
            if (!$parent instanceof MailMessage || !$parent->threadId()) {
                continue;
            }
            if ($msg->threadId() === $parent->threadId()) {
                continue;
            }
            $msg->applyThread(
                $msg->inReplyTo(),
                $msg->referencesRaw(),
                $parent->threadId(),
                $msg->threadSubject() ?: $parent->threadSubject(),
            );
            $dirty = true;
        }
        if ($dirty) {
            $this->em->flush();
        }
    }

    private function attributeToString(mixed $attr): string
    {
        if ($attr === null) {
            return '';
        }
        if (is_string($attr) || is_numeric($attr)) {
            return (string) $attr;
        }
        if (is_object($attr)) {
            if (method_exists($attr, 'first')) {
                $first = $attr->first();
                if ($first !== null) {
                    return (string) $first;
                }
            }
            if (method_exists($attr, 'toString')) {
                return (string) $attr->toString();
            }
            if (method_exists($attr, '__toString')) {
                return (string) $attr;
            }
        }

        return '';
    }

    /** @return list<string> */
    private function attributeToIdList(mixed $attr): array
    {
        if ($attr === null) {
            return [];
        }
        if (is_string($attr)) {
            return MailThreadResolver::parseIdList($attr);
        }
        if (is_object($attr) && method_exists($attr, 'all')) {
            $ids = [];
            foreach ($attr->all() as $item) {
                $n = MailThreadResolver::normalizeId((string) $item);
                if ($n !== null) {
                    $ids[] = $n;
                }
            }

            return array_values(array_unique($ids));
        }

        return MailThreadResolver::parseIdList((string) $attr);
    }

    private function purgeFolderMessages(string $folderId): void
    {
        $ids = $this->em->createQueryBuilder()
            ->select('m.id')
            ->from(MailMessage::class, 'm')
            ->where('m.folderId = :fid')
            ->setParameter('fid', $folderId)
            ->getQuery()->getSingleColumnResult();

        if ($ids === []) {
            return;
        }

        $this->em->createQueryBuilder()->delete(MailBody::class, 'b')
            ->where('b.messageId IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(MailAttachment::class, 'a')
            ->where('a.messageId IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(MailMessage::class, 'm')
            ->where('m.folderId = :fid')->setParameter('fid', $folderId)
            ->getQuery()->execute();
    }

    private function guessRole(string $path, string $name): string
    {
        $hay = strtolower($path . ' ' . $name);
        if (str_contains($hay, 'inbox') || $hay === 'in') {
            return 'inbox';
        }
        if (str_contains($hay, 'sent')) {
            return 'sent';
        }
        if (str_contains($hay, 'draft')) {
            return 'drafts';
        }
        if (str_contains($hay, 'trash') || str_contains($hay, 'deleted')) {
            return 'trash';
        }
        if (str_contains($hay, 'junk') || str_contains($hay, 'spam')) {
            return 'junk';
        }

        return 'other';
    }

    private function storeBlob(string $filename, string $mime, string $content): string
    {
        unset($mime);
        $root = base_path('storage/uploads');
        $dir = 'emailing/' . date('Y/m');
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?: 'file.bin';
        $relative = $dir . '/' . uniqid('att_', true) . '_' . $safe;
        $absolute = $root . '/' . $relative;
        $parent = dirname($absolute);
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }
        file_put_contents($absolute, $content);

        return $relative;
    }
}
