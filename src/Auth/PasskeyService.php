<?php

declare(strict_types=1);

namespace Gereq\Auth;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Entity\WebAuthnCredential;
use Gereq\Config\Repository as Config;
use Gereq\User\Entity\User;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use Symfony\Component\HttpFoundation\RequestStack;

final class PasskeyService
{
    private const CHALLENGE_KEY = 'webauthn.challenge';
    private const PURPOSE_KEY = 'webauthn.purpose';
    private const USER_KEY = 'webauthn.user_id';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Config $config,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return array{publicKey: object} */
    public function registrationOptions(User $user): array
    {
        $webauthn = $this->client();
        $exclude = [];
        foreach ($this->credentialsFor($user) as $credential) {
            $exclude[] = base64_decode($credential->credentialId(), true) ?: '';
        }
        $exclude = array_values(array_filter($exclude, static fn (string $id): bool => $id !== ''));

        $args = $webauthn->getCreateArgs(
            $user->id(),
            $user->email(),
            $user->name(),
            60,
            'preferred',
            'preferred',
            null,
            $exclude,
        );

        $this->storeChallenge($webauthn->getChallenge()->getBinaryString(), 'register', $user->id());

        return ['publicKey' => $args->publicKey];
    }

    /**
     * @param array{id: string, rawId: string, type?: string, response: array<string, mixed>, clientExtensionResults?: mixed} $attestation
     */
    public function completeRegistration(User $user, array $attestation, string $name = 'Passkey'): WebAuthnCredential
    {
        $this->assertPurpose('register', $user->id());
        $challenge = $this->consumeChallenge();

        $clientDataJSON = $this->b64($attestation['response']['clientDataJSON'] ?? null);
        $attestationObject = $this->b64($attestation['response']['attestationObject'] ?? null);
        if ($clientDataJSON === '' || $attestationObject === '') {
            throw new \InvalidArgumentException('Invalid attestation payload.');
        }

        $webauthn = $this->client();
        try {
            $data = $webauthn->processCreate(
                $clientDataJSON,
                $attestationObject,
                $challenge,
                false,
                true,
                false,
            );
        } catch (WebAuthnException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $credentialId = base64_encode($data->credentialId);
        $existing = $this->em->getRepository(WebAuthnCredential::class)->findOneBy(['credentialId' => $credentialId]);
        if ($existing instanceof WebAuthnCredential) {
            throw new \InvalidArgumentException('This passkey is already registered.');
        }

        $transports = null;
        if (isset($attestation['response']['transports']) && is_array($attestation['response']['transports'])) {
            $transports = array_values(array_filter(
                $attestation['response']['transports'],
                static fn ($t): bool => is_string($t) && $t !== '',
            ));
        }

        $aaguid = null;
        if (is_string($data->AAGUID ?? null) && $data->AAGUID !== '') {
            $aaguid = bin2hex($data->AAGUID);
        }

        $credential = new WebAuthnCredential(
            $user,
            $credentialId,
            (string) $data->credentialPublicKey,
            $name,
            (int) ($data->signatureCounter ?? 0),
            $transports,
            $aaguid,
        );
        $this->em->persist($credential);
        $user->setPasskeyEnabled(true);
        $this->em->flush();

        return $credential;
    }

    /**
     * @return array{publicKey: object}
     */
    public function authenticationOptions(?User $user = null): array
    {
        $webauthn = $this->client();
        $ids = [];
        if ($user instanceof User) {
            foreach ($this->credentialsFor($user) as $credential) {
                $binary = base64_decode($credential->credentialId(), true);
                if (is_string($binary) && $binary !== '') {
                    $ids[] = $binary;
                }
            }
            if ($ids === []) {
                throw new \InvalidArgumentException('No passkeys registered.');
            }
        }

        $args = $webauthn->getGetArgs($ids, 60, true, true, true, true, true, 'preferred');
        $this->storeChallenge(
            $webauthn->getChallenge()->getBinaryString(),
            'authenticate',
            $user?->id(),
        );

        return ['publicKey' => $args->publicKey];
    }

    /**
     * @param array{id: string, rawId: string, type?: string, response: array<string, mixed>} $assertion
     */
    public function completeAuthentication(array $assertion, ?User $expectedUser = null): User
    {
        $this->assertPurpose('authenticate', $expectedUser?->id());
        $challenge = $this->consumeChallenge();

        $credentialIdB64 = $this->normalizeId((string) ($assertion['id'] ?? $assertion['rawId'] ?? ''));
        if ($credentialIdB64 === '') {
            $raw = $this->b64($assertion['rawId'] ?? null);
            $credentialIdB64 = $raw !== '' ? base64_encode($raw) : '';
        }
        if ($credentialIdB64 === '') {
            throw new \InvalidArgumentException('Missing credential id.');
        }

        // Client may send base64url; store uses standard base64.
        $credential = $this->findCredential($credentialIdB64);
        if (!$credential instanceof WebAuthnCredential) {
            throw new \InvalidArgumentException('Unknown passkey.');
        }

        $user = $credential->user();
        if (!$user->isActive()) {
            throw new \InvalidArgumentException('User inactive.');
        }
        if ($expectedUser instanceof User && $expectedUser->id() !== $user->id()) {
            throw new \InvalidArgumentException('Passkey does not match user.');
        }

        $clientDataJSON = $this->b64($assertion['response']['clientDataJSON'] ?? null);
        $authenticatorData = $this->b64($assertion['response']['authenticatorData'] ?? null);
        $signature = $this->b64($assertion['response']['signature'] ?? null);
        if ($clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
            throw new \InvalidArgumentException('Invalid assertion payload.');
        }

        $webauthn = $this->client();
        try {
            $webauthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $credential->publicKey(),
                $challenge,
                $credential->signCount(),
                false,
                true,
            );
        } catch (WebAuthnException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $newCount = $webauthn->getSignatureCounter();
        $credential->markUsed($newCount !== null ? $newCount : $credential->signCount());
        if (!$user->passkeyEnabled()) {
            $user->setPasskeyEnabled(true);
        }
        $this->em->flush();

        return $user;
    }

    /** @return list<WebAuthnCredential> */
    public function credentialsFor(User $user): array
    {
        return $this->em->getRepository(WebAuthnCredential::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
        );
    }

    public function deleteCredential(User $user, string $credentialId): bool
    {
        $credential = $this->em->find(WebAuthnCredential::class, $credentialId);
        if (!$credential instanceof WebAuthnCredential || $credential->user()->id() !== $user->id()) {
            return false;
        }
        $this->em->remove($credential);
        $this->em->flush();

        $remaining = $this->credentialsFor($user);
        if ($remaining === []) {
            $user->setPasskeyEnabled(false);
            $this->em->flush();
        }

        return true;
    }

    public function deleteAllFor(User $user): int
    {
        $n = 0;
        foreach ($this->credentialsFor($user) as $credential) {
            $this->em->remove($credential);
            $n++;
        }
        $user->setPasskeyEnabled(false);
        $this->em->flush();

        return $n;
    }

    private function client(): WebAuthn
    {
        $rpName = (string) $this->config->get('security.webauthn.rp_name', $this->config->get('app.name', 'GEREQ'));
        $rpId = $this->resolveRpId();

        return new WebAuthn($rpName, $rpId, ['none'], true);
    }

    /**
     * WebAuthn rpId must be a DNS hostname (not an IP). Prefer the live request host.
     *
     * @throws \InvalidArgumentException when the current host cannot be used as rpId
     */
    private function resolveRpId(): string
    {
        $configured = trim((string) $this->config->get('security.webauthn.rp_id', ''));
        if ($configured !== '' && !in_array($configured, ['0.0.0.0', '*'], true)) {
            $this->assertValidRpId($configured);

            return strtolower($configured);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $host = strtolower($request->getHost());
            if ($host !== '' && $host !== '0.0.0.0') {
                $this->assertValidRpId($host, $request->getPort());

                return $host;
            }
        }

        $appUrl = trim((string) $this->config->get('app.url', ''));
        if ($appUrl !== '' && !str_contains($appUrl, '://')) {
            $appUrl = 'http://' . $appUrl;
        }
        $host = parse_url($appUrl, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && $host !== '0.0.0.0') {
            $this->assertValidRpId($host);

            return strtolower($host);
        }

        return 'localhost';
    }

    private function assertValidRpId(string $host, ?int $port = null): void
    {
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $port ??= $request?->getPort();
        $scheme = $request?->getScheme() ?? 'http';
        $localName = $this->suggestedLocalHostname();
        $portPart = ($port !== null && $port > 0 && !in_array($port, [80, 443], true))
            ? ':' . $port
            : '';
        $url = $scheme . '://' . $localName . $portPart . '/admin';

        throw new \InvalidArgumentException(
            'Passkeys nefungují na IP (' . $host . '). Prohlížeč vyžaduje hostname.'
            . ' Přidejte do /etc/hosts (nebo C:\\Windows\\System32\\drivers\\etc\\hosts) řádek:'
            . ' "' . $host . ' ' . $localName . '"'
            . ' a otevřete ' . $url . '.'
        );
    }

    private function suggestedLocalHostname(): string
    {
        $configured = trim((string) $this->config->get('security.webauthn.local_hostname', ''));
        if ($configured !== '') {
            return strtolower($configured);
        }

        $name = strtolower((string) $this->config->get('app.name', 'gereq'));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $name) ?? 'gereq';
        $slug = trim($slug, '-') ?: 'gereq';

        // Prefer *.local for LAN hosts-file setups (*.localhost is forced to 127.0.0.1 by browsers).
        return $slug . '.local';
    }

    private function storeChallenge(string $binary, string $purpose, ?string $userId): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::CHALLENGE_KEY, base64_encode($binary));
        $session->set(self::PURPOSE_KEY, $purpose);
        if ($userId !== null) {
            $session->set(self::USER_KEY, $userId);
        } else {
            $session->remove(self::USER_KEY);
        }
    }

    private function consumeChallenge(): string
    {
        $session = $this->requestStack->getSession();
        $encoded = $session->get(self::CHALLENGE_KEY);
        $session->remove(self::CHALLENGE_KEY);
        $session->remove(self::PURPOSE_KEY);
        $session->remove(self::USER_KEY);
        if (!is_string($encoded) || $encoded === '') {
            throw new \InvalidArgumentException('WebAuthn challenge expired.');
        }
        $binary = base64_decode($encoded, true);
        if (!is_string($binary) || $binary === '') {
            throw new \InvalidArgumentException('Invalid WebAuthn challenge.');
        }

        return $binary;
    }

    private function assertPurpose(string $expected, ?string $userId): void
    {
        $session = $this->requestStack->getSession();
        if ($session->get(self::PURPOSE_KEY) !== $expected) {
            throw new \InvalidArgumentException('Unexpected WebAuthn ceremony.');
        }
        if ($userId !== null) {
            $stored = $session->get(self::USER_KEY);
            if ($stored !== null && $stored !== $userId) {
                throw new \InvalidArgumentException('WebAuthn user mismatch.');
            }
        }
    }

    private function findCredential(string $credentialIdB64): ?WebAuthnCredential
    {
        $candidates = array_unique([
            $credentialIdB64,
            base64_encode($this->b64($credentialIdB64)),
            rtrim(strtr($credentialIdB64, '+/', '-_'), '='),
        ]);

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $found = $this->em->getRepository(WebAuthnCredential::class)->findOneBy(['credentialId' => $candidate]);
            if ($found instanceof WebAuthnCredential) {
                return $found;
            }
            // Try converting base64url → standard base64 storage
            $binary = $this->b64($candidate);
            if ($binary !== '') {
                $std = base64_encode($binary);
                $found = $this->em->getRepository(WebAuthnCredential::class)->findOneBy(['credentialId' => $std]);
                if ($found instanceof WebAuthnCredential) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function normalizeId(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            return '';
        }
        $binary = $this->b64($id);

        return $binary !== '' ? base64_encode($binary) : '';
    }

    private function b64(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        try {
            return ByteBuffer::fromBase64Url($value)->getBinaryString();
        } catch (\Throwable) {
            $decoded = base64_decode($value, true);

            return is_string($decoded) ? $decoded : '';
        }
    }
}
