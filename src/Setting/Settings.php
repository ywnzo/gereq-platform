<?php

declare(strict_types=1);

namespace Gereq\Setting;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository as Config;
use Gereq\Support\Json;

final class Settings
{
    /** @var array<string, array{type: string, label: string, module: ?string, default: mixed}> */
    private array $definitions = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Config $config,
    ) {
    }

    public function define(string $key, string $type, string $label, mixed $default = null, ?string $module = null): void
    {
        $this->definitions[$key] = [
            'type' => $type,
            'label' => $label,
            'module' => $module,
            'default' => $default,
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $record = $this->em->find(SettingRecord::class, $key);
        if (!$record instanceof SettingRecord) {
            if (array_key_exists($key, $this->definitions)) {
                return $this->definitions[$key]['default'] ?? $default;
            }

            return $this->config->get($key, $default);
        }

        return $this->cast($record->value(), $record->type());
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
    }

    /** @return array<string, array{type: string, label: string, module: ?string, default: mixed}> */
    public function definitions(): array
    {
        return $this->definitions;
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

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($value, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('Šifrování nastavení selhalo.');
        }

        return base64_encode($iv . $cipher);
    }

    private function decrypt(string $payload): string
    {
        $key = (string) $this->config->get('app.key', '');
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);

        return $plain === false ? '' : $plain;
    }
}
