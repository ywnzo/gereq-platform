<?php

declare(strict_types=1);

namespace Gereq\Module;

final class ModuleManifest
{
    /**
     * @param array<string, string> $requires
     * @param array<string, string> $optional
     * @param list<string> $provides
     * @param list<string> $capabilities
     * @param list<array{name: string, label: string, group: string}> $permissions
     * @param list<string> $groups
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $path,
        public readonly string $moduleClass,
        public readonly array $requires = [],
        public readonly array $optional = [],
        public readonly array $provides = [],
        public readonly array $capabilities = [],
        public readonly array $permissions = [],
        public readonly string $author = 'GEREQ',
        public readonly array $groups = [],
        /** Named route for the module's own settings UI (optional; falls back to admin.{id}.settings). */
        public readonly ?string $settingsRoute = null,
        /** module = domain feature; integration = third-party remote adapter (Stripe, Comgate…). */
        public readonly string $kind = 'module',
        /** Admin UI icon key (Material Symbols via Icons). */
        public readonly string $icon = 'modules',
        /** GitLab project path override (e.g. gereq/module-listings). Empty = derived from config. */
        public readonly ?string $repository = null,
    ) {
    }

    public function isIntegration(): bool
    {
        return $this->kind === 'integration';
    }

    /** PSR-4 root for this package (Modules\Foo\ or Integrations\Foo\). */
    public function phpNamespace(): string
    {
        $folder = basename($this->path);

        return ($this->isIntegration() ? 'Integrations\\' : 'Modules\\') . $folder . '\\';
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path): self
    {
        $id = (string) ($data['id'] ?? '');
        $class = (string) ($data['class'] ?? '');

        if ($id === '' || $class === '') {
            throw new \InvalidArgumentException('Manifest modulu musí obsahovat id a class.');
        }

        $groups = [];
        if (is_array($data['groups'] ?? null)) {
            foreach ($data['groups'] as $g) {
                if (is_string($g) && trim($g) !== '') {
                    $groups[] = trim($g);
                }
            }
        }

        $settingsRoute = trim((string) ($data['settings_route'] ?? ''));
        $kind = strtolower(trim((string) ($data['kind'] ?? 'module')));
        if (!in_array($kind, ['module', 'integration'], true)) {
            $kind = 'module';
        }
        // Packages under /integrations default to integration even without kind.
        if ($kind === 'module' && str_contains(str_replace('\\', '/', $path), '/integrations/')) {
            $kind = 'integration';
        }

        $icon = trim((string) ($data['icon'] ?? ''));
        if ($icon === '') {
            $icon = 'modules';
        }

        $repository = trim((string) ($data['repository'] ?? ''));

        return new self(
            id: $id,
            name: (string) ($data['name'] ?? $id),
            version: (string) ($data['version'] ?? '0.1.0'),
            description: (string) ($data['description'] ?? ''),
            path: $path,
            moduleClass: $class,
            requires: is_array($data['requires'] ?? null) ? $data['requires'] : [],
            optional: is_array($data['optional'] ?? null) ? $data['optional'] : [],
            provides: array_values($data['provides'] ?? []),
            capabilities: array_values($data['capabilities'] ?? []),
            permissions: array_values($data['permissions'] ?? []),
            author: (string) ($data['author'] ?? 'GEREQ'),
            groups: array_values(array_unique($groups)),
            settingsRoute: $settingsRoute !== '' ? $settingsRoute : null,
            kind: $kind,
            icon: $icon,
            repository: $repository !== '' ? $repository : null,
        );
    }
}
