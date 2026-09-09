<?php

declare(strict_types=1);

namespace Modules\Emailing\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Event\EventBus;
use Modules\Emailing\Domain\Events\MailboxFolderReconciled;
use Modules\Emailing\Domain\MailAccount;
use Modules\Emailing\Domain\MailFolder;
use Modules\Emailing\Domain\MailLabel;
use Modules\Emailing\Domain\MailMessage;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

final class MailboxActionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailboxSyncService $sync,
        private readonly EventBus $events,
    ) {
    }

    public function markSeen(MailAccount $account, MailMessage $message, bool $seen): void
    {
        $this->withRemoteMessage($account, $message, function (Message $remote) use ($message, $seen): void {
            if ($seen) {
                $remote->setFlag('Seen');
            } else {
                $remote->unsetFlag('Seen');
            }
            $message->setSeen($seen);
            $this->em->flush();
            $this->refreshFolder($message->folderId());
        });
    }

    public function markFlagged(MailAccount $account, MailMessage $message, bool $flagged): void
    {
        $this->withRemoteMessage($account, $message, function (Message $remote) use ($message, $flagged): void {
            if ($flagged) {
                $remote->setFlag('Flagged');
            } else {
                $remote->unsetFlag('Flagged');
            }
            $message->setFlagged($flagged);
            $this->em->flush();
        });
    }

    public function markPinned(MailAccount $account, MailMessage $message, bool $pinned): void
    {
        $this->toggleKeyword($account, $message, ImapKeyword::PINNED, $pinned);
    }

    public function toggleKeyword(MailAccount $account, MailMessage $message, string $keyword, bool $enable): void
    {
        $keyword = ImapKeyword::normalize($keyword);
        if ($keyword === null) {
            throw new \InvalidArgumentException('Neplatný IMAP keyword.');
        }
        $this->withRemoteMessage($account, $message, function (Message $remote) use ($account, $message, $keyword, $enable): void {
            if ($enable) {
                $remote->setFlag($keyword);
            } else {
                $remote->unsetFlag($keyword);
            }
            $keywords = $message->keywords();
            if ($enable && !in_array($keyword, $keywords, true)) {
                $keywords[] = $keyword;
            }
            if (!$enable) {
                $keywords = array_values(array_filter($keywords, static fn (string $k): bool => $k !== $keyword));
            }
            $message->setKeywords($keywords);
            if ($keyword !== ImapKeyword::PINNED) {
                $this->ensureLabel($account->id(), $keyword, $keyword);
            }
            $this->em->flush();
        });
    }

    /** @param list<string> $keywords */
    public function setKeywords(MailAccount $account, MailMessage $message, array $keywords): void
    {
        $normalized = [];
        foreach ($keywords as $kw) {
            $n = ImapKeyword::normalize((string) $kw);
            if ($n !== null) {
                $normalized[] = $n;
            }
        }
        $normalized = array_values(array_unique($normalized));
        $this->withRemoteMessage($account, $message, function (Message $remote) use ($account, $message, $normalized): void {
            $current = $message->keywords();
            foreach (array_diff($normalized, $current) as $kw) {
                $remote->setFlag($kw);
                if ($kw !== ImapKeyword::PINNED) {
                    $this->ensureLabel($account->id(), $kw, $kw);
                }
            }
            foreach (array_diff($current, $normalized) as $kw) {
                $remote->unsetFlag($kw);
            }
            $message->setKeywords($normalized);
            $this->em->flush();
        });
    }

    public function createLabel(MailAccount $account, string $name, ?string $color = null): MailLabel
    {
        $keyword = ImapKeyword::fromLabelName($name);
        if ($keyword === null) {
            throw new \InvalidArgumentException('Neplatný název štítku.');
        }
        $existing = $this->em->getRepository(MailLabel::class)->findOneBy([
            'accountId' => $account->id(),
            'keyword' => $keyword,
        ]);
        if ($existing instanceof MailLabel) {
            $existing->setName($name);
            if ($color !== null) {
                $existing->setColor($color);
            }
            $this->em->flush();

            return $existing;
        }
        $label = new MailLabel($account->id(), $name, $keyword, $color);
        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    public function moveMessage(MailAccount $account, MailMessage $message, MailFolder $target): void
    {
        if ($target->id() === $message->folderId()) {
            return;
        }
        $oldFolderId = $message->folderId();
        $messageId = $message->id();
        $this->withRemoteMessage($account, $message, function (Message $remote) use ($account, $target, $messageId, $oldFolderId): void {
            $moved = $remote->move($target->remotePath(), true);
            $this->sync->purgeLocalMessage($messageId);
            if ($moved instanceof Message) {
                $this->sync->indexRemoteMessage($account, $target, $moved);
            }
            $this->refreshFolder($oldFolderId);
            $this->refreshFolder($target->id());
            $this->events->dispatch(MailboxFolderReconciled::make($target->id(), [
                'action' => 'move',
                'message_id' => $messageId,
                'from_folder_id' => $oldFolderId,
            ]));
        });
    }

    public function copyMessage(MailAccount $account, MailMessage $message, MailFolder $target): void
    {
        $this->withRemoteMessage($account, $message, function (Message $remote) use ($account, $target): void {
            $copied = $remote->copy($target->remotePath(), true);
            if ($copied instanceof Message) {
                $this->sync->indexRemoteMessage($account, $target, $copied);
            }
            $this->refreshFolder($target->id());
        });
    }

    public function deleteMessage(MailAccount $account, MailMessage $message): void
    {
        $trash = $this->findRoleFolder($account->id(), 'trash');
        $oldFolderId = $message->folderId();
        $messageId = $message->id();
        $this->withRemoteMessage($account, $message, function (Message $remote) use ($trash, $message, $messageId, $oldFolderId): void {
            if ($trash instanceof MailFolder && $message->folderId() !== $trash->id()) {
                $remote->delete(true, $trash->remotePath(), true);
            } else {
                $remote->delete(true);
            }
            $this->sync->purgeLocalMessage($messageId);
            $this->refreshFolder($oldFolderId);
            if ($trash instanceof MailFolder) {
                $this->refreshFolder($trash->id());
            }
        });
    }

    public function archiveMessage(MailAccount $account, MailMessage $message): void
    {
        $archive = $this->findOrCreateArchive($account);
        $this->moveMessage($account, $message, $archive);
    }

    public function markAllRead(MailAccount $account, MailFolder $folder): void
    {
        /** @var list<MailMessage> $unread */
        $unread = $this->em->getRepository(MailMessage::class)->findBy([
            'folderId' => $folder->id(),
            'isSeen' => false,
        ]);
        if ($unread === []) {
            return;
        }
        $this->withClient($account, function (Client $client) use ($folder, $unread): void {
            $remote = $client->getFolderByPath($folder->remotePath());
            if ($remote === null) {
                throw new \RuntimeException('Složka na serveru nenalezena.');
            }
            foreach ($unread as $message) {
                try {
                    $msg = $remote->messages()->getMessageByUid($message->uid());
                    if ($msg instanceof Message) {
                        $msg->setFlag('Seen');
                    }
                    $message->setSeen(true);
                } catch (\Throwable) {
                }
            }
            $this->em->flush();
            $this->refreshFolder($folder->id());
        });
    }

    public function emptyFolder(MailAccount $account, MailFolder $folder): void
    {
        $this->withClient($account, function (Client $client) use ($folder): void {
            $remote = $client->getFolderByPath($folder->remotePath());
            if ($remote === null) {
                throw new \RuntimeException('Složka na serveru nenalezena.');
            }
            $messages = $remote->messages()->all()->setFetchBody(false)->get();
            foreach ($messages as $msg) {
                /** @var Message $msg */
                $msg->delete(false);
            }
            try {
                $client->getConnection()->expunge($folder->remotePath());
            } catch (\Throwable) {
                try {
                    $client->expunge();
                } catch (\Throwable) {
                }
            }
            $this->sync->purgeFolderMessagesPublic($folder->id());
            $folder->setCounts(0, 0);
            $folder->setSyncCursor($folder->uidValidity(), null);
            $this->em->flush();
        });
    }

    public function createSubfolder(MailAccount $account, MailFolder $parent, string $name): MailFolder
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Název složky je povinný.');
        }
        $delimiter = str_contains($parent->remotePath(), '/') ? '/' : '.';
        $path = rtrim($parent->remotePath(), $delimiter) . $delimiter . $name;

        return $this->withClient($account, function (Client $client) use ($account, $parent, $name, $path): MailFolder {
            $client->createFolder($path);
            $created = new MailFolder($account->id(), $name, $path, 'other');
            $created->setSortOrder($parent->sortOrder() + 1);
            $this->em->persist($created);
            $this->em->flush();

            return $created;
        });
    }

    public function renameFolder(MailAccount $account, MailFolder $folder, string $name): void
    {
        if ($folder->isSystemRole()) {
            throw new \RuntimeException('Systémovou složku nelze přejmenovat.');
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Název složky je povinný.');
        }
        $this->withClient($account, function (Client $client) use ($folder, $name): void {
            $remote = $client->getFolderByPath($folder->remotePath());
            if ($remote === null) {
                throw new \RuntimeException('Složka na serveru nenalezena.');
            }
            $delimiter = str_contains($folder->remotePath(), '/') ? '/' : '.';
            $parts = explode($delimiter, $folder->remotePath());
            array_pop($parts);
            $parts[] = $name;
            $newPath = implode($delimiter, $parts);
            $remote->move($newPath);
            $folder->setName($name);
            $folder->setRemotePath($newPath);
            $this->em->flush();
        });
    }

    public function deleteFolder(MailAccount $account, MailFolder $folder): void
    {
        if ($folder->isSystemRole()) {
            throw new \RuntimeException('Systémovou složku nelze smazat.');
        }
        $this->withClient($account, function (Client $client) use ($folder): void {
            $remote = $client->getFolderByPath($folder->remotePath());
            if ($remote !== null) {
                $remote->delete(true);
            }
            $this->sync->purgeFolderMessagesPublic($folder->id());
            $this->em->remove($folder);
            $this->em->flush();
        });
    }

    public function setFolderColor(MailFolder $folder, ?string $color): void
    {
        $folder->setColor($color);
        $this->em->flush();
    }

    public function setFolderFavorite(MailFolder $folder, bool $favorite): void
    {
        $folder->setFavorite($favorite);
        $this->em->flush();
    }

    public function moveFolderOrder(MailAccount $account, MailFolder $folder, string $direction): void
    {
        /** @var list<MailFolder> $folders */
        $folders = $this->em->getRepository(MailFolder::class)->findBy(
            ['accountId' => $account->id()],
            ['sortOrder' => 'ASC', 'name' => 'ASC'],
        );
        $idx = null;
        foreach ($folders as $i => $f) {
            if ($f->id() === $folder->id()) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return;
        }
        $swap = $direction === 'up' ? $idx - 1 : $idx + 1;
        if ($swap < 0 || $swap >= count($folders)) {
            return;
        }
        $tmp = $folders[$idx];
        $folders[$idx] = $folders[$swap];
        $folders[$swap] = $tmp;
        foreach ($folders as $i => $f) {
            $f->setSortOrder($i);
        }
        $this->em->flush();
    }

    /**
     * @template T
     * @param callable(Client): T $fn
     * @return T
     */
    private function withClient(MailAccount $account, callable $fn): mixed
    {
        $client = $this->sync->connect($account);
        try {
            return $fn($client);
        } finally {
            try {
                $client->disconnect();
            } catch (\Throwable) {
            }
        }
    }

    /** @param callable(Message): void $fn */
    private function withRemoteMessage(MailAccount $account, MailMessage $message, callable $fn): void
    {
        $folder = $this->em->find(MailFolder::class, $message->folderId());
        if (!$folder instanceof MailFolder) {
            throw new \RuntimeException('Složka zprávy neexistuje.');
        }
        $this->withClient($account, function (Client $client) use ($folder, $message, $fn): void {
            $remote = $client->getFolderByPath($folder->remotePath());
            if ($remote === null) {
                throw new \RuntimeException('Vzdálená složka nenalezena.');
            }
            $msg = $remote->messages()->getMessageByUid($message->uid());
            if (!$msg instanceof Message) {
                throw new \RuntimeException('Zpráva na serveru nenalezena.');
            }
            $fn($msg);
        });
    }

    private function refreshFolder(string $folderId): void
    {
        $folder = $this->em->find(MailFolder::class, $folderId);
        if ($folder instanceof MailFolder) {
            $this->sync->refreshFolderCounts($folder);
        }
    }

    private function ensureLabel(string $accountId, string $keyword, string $name): MailLabel
    {
        $existing = $this->em->getRepository(MailLabel::class)->findOneBy([
            'accountId' => $accountId,
            'keyword' => $keyword,
        ]);
        if ($existing instanceof MailLabel) {
            return $existing;
        }
        $label = new MailLabel($accountId, $name, $keyword);
        $this->em->persist($label);

        return $label;
    }

    private function findRoleFolder(string $accountId, string $role): ?MailFolder
    {
        $folder = $this->em->getRepository(MailFolder::class)->findOneBy([
            'accountId' => $accountId,
            'role' => $role,
        ]);

        return $folder instanceof MailFolder ? $folder : null;
    }

    private function findOrCreateArchive(MailAccount $account): MailFolder
    {
        foreach ($this->em->getRepository(MailFolder::class)->findBy(['accountId' => $account->id()]) as $folder) {
            /** @var MailFolder $folder */
            if (str_contains(strtolower($folder->name()), 'archive') || str_contains(strtolower($folder->remotePath()), 'archive')) {
                return $folder;
            }
        }

        return $this->withClient($account, function (Client $client) use ($account): MailFolder {
            $path = 'Archive';
            try {
                $client->createFolder($path);
            } catch (\Throwable) {
            }
            $folder = new MailFolder($account->id(), 'Archive', $path, 'other');
            $this->em->persist($folder);
            $this->em->flush();

            return $folder;
        });
    }
}
