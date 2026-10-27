<?php

declare(strict_types=1);

namespace Gereq\Setting;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Config\Repository as Config;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingWriter;
use Gereq\Support\Json;

final class Settings
{
    /** @var array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>, global: bool}> */
    private array $definitions = [];

    /** @var array<string, mixed>|null null = not loaded yet */
    private ?array $values = null;

    private bool $storageAvailable = true;

    /** @var array<string, mixed> in-request overlay from staging (settings keys) */
    private array $stagingOverlay = [];

    private bool $stagingOverlayLoaded = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Config $config,
        private readonly ContentReadContext $readContext,
        private readonly PublishableRegistry $publishables,
        private readonly StagingWriter $stagingWriter,
    ) {
    }

    /**
     * @param array<string, string> $options Select options (value => label); not UI flags
     * @param bool $global Show in global admin Settings (false = module's own UI only)
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

    public function storageAvailable(): bool
    {
        $this->warm();

        return $this->storageAvailable;
    }

    public function has(string $key): bool
    {
        $this->warm();

        return array_key_exists($key, $this->values ?? []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->readContext->isStaging() && $this->publishables->isSettingsKey($key)) {
            $this->warmStagingOverlay();
            if (array_key_exists($key, $this->stagingOverlay)) {
                return $this->stagingOverlay[$key];
            }
        }

        return $this->getLive($key, $default);
    }

    /** Production value only (ignores staging overlay). */
    public function getLive(string $key, mixed $default = null): mixed
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
        if ($this->readContext->isStaging() && $this->publishables->isSettingsKey($key)) {
            $live = $this->getLive($key, $this->definitions[$key]['default'] ?? null);
            if ($this->sameSettingValue($live, $value, $type)) {
                // Form posts every field; only real diffs belong in staging.
                $this->stagingWriter->unstageSetting($key);
                unset($this->stagingOverlay[$key]);

                return;
            }

            $label = $this->definitions[$key]['label'] ?? $key;
            $this->stagingWriter->stageSetting($key, $value, $type, $module ?? ($this->definitions[$key]['module'] ?? null), (string) $label);
            $this->stagingOverlay[$key] = $value;
            $this->stagingOverlayLoaded = true;

            return;
        }

        $this->setLive($key, $value, $type, $module);
    }

    private function sameSettingValue(mixed $a, mixed $b, string $type): bool
    {
        return $this->serialize($a, $type) === $this->serialize($b, $type);
    }

    /** Write directly to production settings (used by publish apply). */
    public function setLive(string $key, mixed $value, string $type = SettingRecord::TYPE_STRING, ?string $module = null): void
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
        unset($this->stagingOverlay[$key]);
    }

    public function typeOf(string $key): string
    {
        if (isset($this->definitions[$key]['type'])) {
            return (string) $this->definitions[$key]['type'];
        }

        $this->warm();
        try {
            $record = $this->em->find(SettingRecord::class, $key);
            if ($record instanceof SettingRecord) {
                return $record->type();
            }
        } catch (\Throwable) {
        }

        return SettingRecord::TYPE_STRING;
    }

    /** @return array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>, global: bool}> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Definitions visible in global admin Settings.
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

    /**
     * Settings registered by a module / plugin (including non-global keys).
     *
     * @return array<string, array{type: string, label: string, module: ?string, default: mixed, options: array<string, string>, global: bool}>
     */
    public function definitionsForModule(string $moduleId): array
    {
        $prefix = $moduleId . '.';

        return array_filter(
            $this->definitions,
            static function (array $d, string $key) use ($moduleId, $prefix): bool {
                $module = $d['module'] ?? null;

                return $module === $moduleId || str_starts_with($key, $prefix);
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Loads all settings for the request in one SELECT * (eliminates N+1 from find-per-key). */
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
            // table may not exist yet (install / migrate)
            $this->storageAvailable = false;
            $this->values = [];
        }
    }

    private function warmStagingOverlay(): void
    {
        if ($this->stagingOverlayLoaded) {
            return;
        }

        $this->stagingOverlayLoaded = true;
        try {
            $entries = $this->em->createQueryBuilder()
                ->select('e')
                ->from(\Gereq\Publish\Entity\ContentStagingEntry::class, 'e')
                ->where('e.resourceType = :type')
                ->setParameter('type', 'setting')
                ->getQuery()
                ->getResult();

            /** @var list<\Gereq\Publish\Entity\ContentStagingEntry> $entries */
            foreach ($entries as $entry) {
                $payload = $entry->payload();
                $this->stagingOverlay[$entry->resourceKey()] = $payload['value'] ?? null;
            }
        } catch (\Throwable) {
            // staging table may not exist yet
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
