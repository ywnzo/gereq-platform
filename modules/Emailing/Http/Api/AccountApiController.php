<?php

declare(strict_types=1);

namespace Modules\Emailing\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiResponse;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Queue\Queue;
use Modules\Emailing\Application\CredentialVault;
use Modules\Emailing\Application\Jobs\SyncAccountJob;
use Modules\Emailing\Application\MailHostNormalizer;
use Modules\Emailing\Domain\MailAccount;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class AccountApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly CredentialVault $vault,
        private readonly Queue $queue,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): JsonResponse
    {
        $rows = $this->em->getRepository(MailAccount::class)->findBy([], ['name' => 'ASC']);

        return ApiResponse::collection(array_map($this->serialize(...), $rows));
    }

    public function show(string $id): JsonResponse
    {
        $account = $this->em->find(MailAccount::class, $id);
        if (!$account instanceof MailAccount) {
            return ApiResponse::notFound();
        }

        return ApiResponse::data($this->serialize($account));
    }

    public function store(Request $request): JsonResponse
    {
        $raw = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($raw)) {
            return ApiResponse::error('validation_error', 'Neplatné JSON tělo.', [], 422);
        }
        $name = trim((string) ($raw['name'] ?? ''));
        $email = trim((string) ($raw['email'] ?? ''));
        if ($name === '' || $email === '') {
            return ApiResponse::error('validation_error', 'name a email jsou povinné.', [], 422);
        }

        $account = new MailAccount($name, $email);
        $this->apply($account, $raw, true);
        $this->em->persist($account);
        $this->em->flush();

        return ApiResponse::data($this->serialize($account), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = $this->em->find(MailAccount::class, $id);
        if (!$account instanceof MailAccount) {
            return ApiResponse::notFound();
        }
        $raw = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($raw)) {
            return ApiResponse::error('validation_error', 'Neplatné JSON tělo.', [], 422);
        }
        $this->apply($account, $raw, false);
        $this->em->flush();

        return ApiResponse::data($this->serialize($account));
    }

    public function destroy(string $id): JsonResponse
    {
        $account = $this->em->find(MailAccount::class, $id);
        if (!$account instanceof MailAccount) {
            return ApiResponse::notFound();
        }
        $this->em->remove($account);
        $this->em->flush();

        return ApiResponse::data(['id' => $id, 'deleted' => true]);
    }

    public function sync(string $id): JsonResponse
    {
        $account = $this->em->find(MailAccount::class, $id);
        if (!$account instanceof MailAccount) {
            return ApiResponse::notFound();
        }
        $this->queue->dispatch(new SyncAccountJob($account->id()));

        return ApiResponse::data(['id' => $id, 'sync_queued' => true]);
    }

    /** @param array<string, mixed> $raw */
    private function apply(MailAccount $account, array $raw, bool $creating): void
    {
        if (isset($raw['name'])) {
            $account->setName((string) $raw['name']);
        }
        if (isset($raw['email'])) {
            $account->setEmail((string) $raw['email']);
        }

        $smtpPass = isset($raw['smtp_password']) ? (string) $raw['smtp_password'] : '';
        $account->setSmtp(
            MailHostNormalizer::normalize((string) ($raw['smtp_host'] ?? $account->smtpHost())),
            (int) ($raw['smtp_port'] ?? $account->smtpPort()),
            (string) ($raw['smtp_encryption'] ?? $account->smtpEncryption()),
            (string) ($raw['smtp_username'] ?? $account->smtpUsername()),
            $smtpPass !== '' ? $this->vault->encrypt($smtpPass) : ($creating ? $this->vault->encrypt('') : null),
        );

        $inPass = isset($raw['incoming_password']) ? (string) $raw['incoming_password'] : '';
        if ($inPass === '' && $smtpPass !== '') {
            $inPass = $smtpPass;
        }
        $account->setIncoming(
            (string) ($raw['incoming_protocol'] ?? $account->incomingProtocol()),
            MailHostNormalizer::normalize((string) ($raw['incoming_host'] ?? $account->incomingHost())),
            (int) ($raw['incoming_port'] ?? $account->incomingPort()),
            (string) ($raw['incoming_encryption'] ?? $account->incomingEncryption()),
            (string) ($raw['incoming_username'] ?? $account->incomingUsername()),
            $inPass !== '' ? $this->vault->encrypt($inPass) : ($creating ? $this->vault->encrypt('') : null),
        );

        if (array_key_exists('is_default', $raw)) {
            if ($raw['is_default']) {
                foreach ($this->em->getRepository(MailAccount::class)->findBy(['isDefault' => true]) as $row) {
                    /** @var MailAccount $row */
                    $row->setDefault(false);
                }
            }
            $account->setDefault((bool) $raw['is_default']);
        }
        if (array_key_exists('is_active', $raw)) {
            $account->setActive((bool) $raw['is_active']);
        } elseif ($creating) {
            $account->setActive(true);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(MailAccount $account): array
    {
        return [
            'id' => $account->id(),
            'name' => $account->name(),
            'email' => $account->email(),
            'smtp_host' => $account->smtpHost(),
            'smtp_port' => $account->smtpPort(),
            'smtp_encryption' => $account->smtpEncryption(),
            'smtp_username' => $account->smtpUsername(),
            'incoming_protocol' => $account->incomingProtocol(),
            'incoming_host' => $account->incomingHost(),
            'incoming_port' => $account->incomingPort(),
            'incoming_encryption' => $account->incomingEncryption(),
            'incoming_username' => $account->incomingUsername(),
            'is_default' => $account->isDefault(),
            'is_active' => $account->isActive(),
            'last_synced_at' => $account->lastSyncedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $account->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $account->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
