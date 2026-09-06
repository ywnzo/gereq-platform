<?php

declare(strict_types=1);

namespace Gereq\Setting;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository as Config;
use Gereq\Support\Json;

final class Settings
{
    /** @var array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>, global: bool}> */
    private array $definitions = [];

    /** @var array<string, mixed>|null null = ještě nenačteno */
    private ?array $values = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, string> $options Select options (value => label); not UI flags
     * @param bool $global Zobrazit v globálním admin Nastavení (false = jen vlastní UI modulu)
     */
    public function define(
        string $key,
        string $type,
        string $label,
        mixed $default = null,
        ?string $module = null,
        array $options = [],
        bool $global = true,
    ): void {
        $this->definitions[$key] = [
            'type' => $type,
            'label' => $label,
            'module' => $module,
            'default' => $default,
            'options' => $options,
            'global' => $global,
        ];
    }

    public function has(string $key): bool
    {
        $this->warm();

        return array_key_exists($key, $this->values ?? []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->warm();

        if (array_key_exists($key, $this->values ?? [])) {
            return $this->values[$key];
        }

        if (array_key_exists($key, $this->definitions)) {
            return $this->definitions[$key]['default'] ?? $default;
        }

        return $this->config->get($key, $default);
    }

    public function set(string $key, mixed $value, string $type = SettingRecord::TYPE_STRING, ?string $module = null): void
    {
        $stored = $this->serialize($value, $type);
        $record = $this->em->find(SettingRecord::class, $key);

        if ($record instanceof SettingRecord) {
            $record->setValue($stored);
            $record->setType($type);
        } else {
            $record = new SettingRecord($key, $stored, $type, $module);
            $this->em->persist($record);
        }

        $this->em->flush();
        $this->warm();
        $this->values[$key] = $this->cast($stored, $type);
    }

    /** @return array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>, global: bool}> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Definice viditelné v globálním admin Nastavení.
     *
     * @return array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>, global: bool}>
     */
    public function globalDefinitions(): array
    {
        return array_filter(
            $this->definitions,
            static fn (array $d): bool => ($d['global'] ?? true) === true,
        );
    }

    /** Jedním SELECT * načte všechna nastavení pro request (eliminuje N+1 z find-per-key). */
    private function warm(): void
    {
        if ($this->values !== null) {
            return;
        }

        $this->values = [];
        try {
            /** @var list<SettingRecord> $rows */
            $rows = $this->em->getRepository(SettingRecord::class)->findAll();
            foreach ($rows as $record) {
                $this->values[$record->keyName()] = $this->cast($record->value(), $record->type());
            }
        } catch (\Throwable) {
            // tabulka ještě nemusí existovat (install / migrate)
            $this->values = [];
        }
    }

    private function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            SettingRecord::TYPE_BOOLEAN => $value === '1' || $value === 'true',
            SettingRecord::TYPE_INTEGER => (int) $value,
            SettingRecord::TYPE_JSON => $value !== null ? Json::decode($value) : null,
            SettingRecord::TYPE_ENCRYPTED => $this->decrypt((string) $value),
            default => $value,
        };
    }

    private function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            SettingRecord::TYPE_BOOLEAN => $value ? '1' : '0',
            SettingRecord::TYPE_INTEGER => (string) (int) $value,
            SettingRecord::TYPE_JSON => Json::encode($value),
            SettingRecord::TYPE_ENCRYPTED => $this->encrypt((string) $value),
            default => (string) $value,
        };
    }

    private function encrypt(string $value): string
    {
        $key = (string) $this->config->get('app.key', '');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY není nastavený. Šifrované nastavení nelze uložit.');
        }

        $binaryKey = hash('sha256', $key, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $binaryKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false || $tag === '') {
            throw new \RuntimeException('Šifrování nastavení selhalo.');
        }

        return 'gcm:' . base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $payload): string
    {
        $key = (string) $this->config->get('app.key', '');
        $binaryKey = hash('sha256', $key, true);

        if (str_starts_with($payload, 'gcm:')) {
            $raw = base64_decode(substr($payload, 4), true);
            if ($raw === false || strlen($raw) < 29) {
                return '';
            }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $binaryKey, OPENSSL_RAW_DATA, $iv, $tag);

            return $plain === false ? '' : $plain;
        }

        // Legacy AES-CBC payloads (pre-GCM).
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $binaryKey, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? '' : $plain;
    }
}
