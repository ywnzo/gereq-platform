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
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, string $path): self
    {
        $id = (string) ($data['id'] ?? '');
        $class = (string) ($data['class'] ?? '');

        if ($id === '' || $class === '') {
            throw new \InvalidArgumentException('Manifest modulu musí obsahovat id a class.');
        }

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
        );
    }
}
