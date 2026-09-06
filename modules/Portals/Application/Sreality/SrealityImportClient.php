<?php

declare(strict_types=1);

namespace Modules\Portals\Application\Sreality;

/**
 * Klient importního XML-RPC rozhraní Sreality (https://import.sreality.cz/RPC2).
 *
 * Autorizace: getHash → přepočet session → login; před každým dalším voláním znovu rotovat session.
 * Heslo v konfiguraci musí být MD5 hash (viz dokumentace).
 */
final class SrealityImportClient
{
    private ?string $sessionId = null;

    public function __construct(
        private readonly XmlRpcClient $rpc,
        private readonly int $clientId,
        private readonly string $passwordMd5,
        private readonly string $softwareKey,
    ) {
    }

    public static function fromConfig(array $config): self
    {
        $endpoint = trim((string) ($config['api_base_url'] ?? $config['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://import.sreality.cz/RPC2';
        }
        $clientId = (int) ($config['client_id'] ?? 0);
        $password = trim((string) ($config['password'] ?? ''));
        $key = trim((string) ($config['software_key'] ?? $config['import_key'] ?? ''));
        if ($clientId <= 0 || $password === '' || $key === '') {
            throw new \InvalidArgumentException('Sreality: chybí client_id, password (MD5) nebo software_key.');
        }

        return new self(new XmlRpcClient($endpoint), $clientId, $password, $key);
    }

    /** @return array{status: int, statusMessage: string, output: mixed} */
    public function login(): array
    {
        $hash = $this->callRaw('getHash', [$this->clientId]);
        $this->assertOk($hash, 'getHash');
        $session = $this->extractSessionId($hash);
        $this->sessionId = $this->rotate($session);
        $login = $this->callRaw('login', [$this->sessionId]);
        $this->assertOk($login, 'login');

        return $login;
    }

    /** @return array{status: int, statusMessage: string, output: mixed} */
    public function logout(): array
    {
        $result = $this->authed('logout');
        $this->sessionId = null;

        return $result;
    }

    /**
     * @param array<string, mixed> $advert
     * @return array{status: int, statusMessage: string, output: mixed}
     */
    public function addAdvert(array $advert): array
    {
        return $this->authed('addAdvert', [$advert]);
    }

    /** @return array{status: int, statusMessage: string, output: mixed} */
    public function delAdvert(?int $advertId = null, string $advertRkid = ''): array
    {
        return $this->authed('delAdvert', [$advertId ?? 0, $advertRkid]);
    }

    /** @return array{status: int, statusMessage: string, output: mixed} */
    public function listAdvert(): array
    {
        return $this->authed('listAdvert');
    }

    /**
     * @param array<string, mixed> $photoData
     * @return array{status: int, statusMessage: string, output: mixed}
     */
    public function addPhoto(?int $advertId, string $advertRkid, array $photoData): array
    {
        return $this->authed('addPhoto', [$advertId ?? 0, $advertRkid, $photoData]);
    }

    /** @return array{status: int, statusMessage: string, output: mixed} */
    public function delPhoto(?int $photoId = null, string $photoRkid = ''): array
    {
        return $this->authed('delPhoto', [$photoId ?? 0, $photoRkid]);
    }

    /** @return array{status: int, statusMessage: string, output: mixed} */
    public function listPhoto(?int $advertId, string $advertRkid): array
    {
        return $this->authed('listPhoto', [$advertId ?? 0, $advertRkid]);
    }

    /** @return array{status: int, statusMessage: string, output: mixed} */
    public function version(): array
    {
        return $this->callRaw('version', []);
    }

    public function endpoint(): string
    {
        return $this->rpc->endpoint();
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * @param list<mixed> $extra
     * @return array{status: int, statusMessage: string, output: mixed}
     */
    private function authed(string $method, array $extra = []): array
    {
        if ($this->sessionId === null) {
            $this->login();
        }
        $this->sessionId = $this->rotate((string) $this->sessionId);
        $params = array_merge([$this->sessionId], $extra);

        return $this->callRaw($method, $params);
    }

    /**
     * @param list<mixed> $params
     * @return array{status: int, statusMessage: string, output: mixed}
     */
    private function callRaw(string $method, array $params): array
    {
        $raw = $this->rpc->call($method, $params);
        $status = (int) ($raw['status'] ?? 0);
        $message = (string) ($raw['statusMessage'] ?? '');
        $output = $raw['output'] ?? [];

        return [
            'status' => $status,
            'statusMessage' => $message,
            'output' => $output,
        ];
    }

    /** @param array{status: int, statusMessage: string, output: mixed} $response */
    private function assertOk(array $response, string $method): void
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(sprintf(
                'Sreality %s [%d]: %s',
                $method,
                $response['status'],
                $response['statusMessage'] !== '' ? $response['statusMessage'] : 'chyba',
            ));
        }
    }

    /** @param array{status: int, statusMessage: string, output: mixed} $hash */
    private function extractSessionId(array $hash): string
    {
        $output = $hash['output'];
        if (is_array($output) && isset($output[0]) && is_array($output[0])) {
            $sid = (string) ($output[0]['sessionId'] ?? '');
            if ($sid !== '') {
                return $sid;
            }
        }
        if (is_array($output) && isset($output['sessionId'])) {
            return (string) $output['sessionId'];
        }

        throw new \RuntimeException('Sreality getHash: chybí sessionId.');
    }

    private function rotate(string $sessionId): string
    {
        // Dokumentace: fixed(48) + md5(sessionId + password_md5 + software_key)
        // password je už MD5 hash hesla.
        $var = md5($sessionId . $this->passwordMd5 . $this->softwareKey);

        return substr($sessionId, 0, 48) . $var;
    }
}
