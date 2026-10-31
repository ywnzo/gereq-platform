<?php

declare(strict_types=1);

namespace Gereq\Module;

use Symfony\Contracts\Translation\TranslatorInterface;

/** Immutable package limits are supplied by the operator-owned environment, never Settings. */
final class ManagedModulePolicy
{
    /** @param list<string>|null $allowed */
    public function __construct(private readonly ?array $allowed, private readonly bool $webRequest)
    {
    }

    public static function fromEnvironment(): self
    {
        $raw = $_ENV['GEREQ_PACKAGE_MODULES'] ?? $_SERVER['GEREQ_PACKAGE_MODULES'] ?? getenv('GEREQ_PACKAGE_MODULES');
        $package = $_ENV['GEREQ_PACKAGE'] ?? $_SERVER['GEREQ_PACKAGE'] ?? getenv('GEREQ_PACKAGE');
        if ($raw === false && $package === 'funnel-v1') {
            $raw = 'cms,site_inquiries,analytics';
        }
        if ($raw === false) {
            return new self(null, PHP_SAPI !== 'cli');
        }
        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Invalid managed module policy.');
        }
        $allowed = explode(',', $raw);
        foreach ($allowed as $id) {
            if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $id)) {
                throw new \RuntimeException('Invalid managed module policy.');
            }
        }
        if (count($allowed) !== count(array_unique($allowed))) {
            throw new \RuntimeException('Invalid managed module policy.');
        }

        return new self($allowed, PHP_SAPI !== 'cli');
    }

    public function assertEnable(string $id, TranslatorInterface $translator): void
    {
        $this->assertMutation($translator);
        if ($this->allowed !== null && !in_array($id, $this->allowed, true)) {
            throw new \RuntimeException($translator->trans('modules.error.managed_package', ['%id%' => $id]));
        }
    }

    public function assertMutation(TranslatorInterface $translator): void
    {
        if ($this->allowed !== null && $this->webRequest) {
            throw new \RuntimeException($translator->trans('modules.error.managed_package', ['%id%' => '']));
        }
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    public function filterEnabled(array $ids): array
    {
        return $this->allowed === null ? $ids : array_values(array_intersect($ids, $this->allowed));
    }
}
