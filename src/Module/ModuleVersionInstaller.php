<?php

declare(strict_types=1);

namespace Gereq\Module;

use Gereq\Config\Repository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ModuleVersionInstaller
{
    public function __construct(
        private readonly GitLabModuleClient $gitlab,
        private readonly ModuleVersionStore $store,
        private readonly ModuleManager $modules,
        private readonly Repository $config,
        private readonly TranslatorInterface $translator,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /**
     * @return list<array{version: string, tag: string, committed_at: ?string, cached: bool, active: bool, footprint: string, is_downgrade: bool, is_upgrade: bool}>
     */
    public function listVersions(string $moduleId, bool $refresh = false): array
    {
        $manifest = $this->requireManifest($moduleId);
        $active = GitLabModuleClient::normalizeVersion($manifest->version);
        $cached = array_fill_keys($this->store->cachedVersions($moduleId), true);

        $remote = [];
        if ($this->gitlab->configured()) {
            $remote = $this->cachedTags($manifest, $refresh);
        }

        $byVersion = [];
        foreach ($remote as $row) {
            $v = $row['version'];
            $byVersion[$v] = [
                'version' => $v,
                'tag' => $row['tag'],
                'committed_at' => $row['committed_at'],
                'cached' => isset($cached[$v]),
                'active' => $v === $active,
                'footprint' => isset($cached[$v]) ? $this->store->footprint($moduleId, $v) : '',
                'is_downgrade' => version_compare($v, $active) < 0,
                'is_upgrade' => version_compare($v, $active) > 0,
            ];
        }
        foreach (array_keys($cached) as $v) {
            if (!isset($byVersion[$v])) {
                $byVersion[$v] = [
                    'version' => $v,
                    'tag' => 'v' . $v,
                    'committed_at' => null,
                    'cached' => true,
                    'active' => $v === $active,
                    'footprint' => $this->store->footprint($moduleId, $v),
                    'is_downgrade' => version_compare($v, $active) < 0,
                    'is_upgrade' => version_compare($v, $active) > 0,
                ];
            } else {
                $byVersion[$v]['footprint'] = $this->store->footprint($moduleId, $v);
            }
        }

        $list = array_values($byVersion);
        usort($list, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $list;
    }

    /**
     * Download tag into version cache. Returns cache path.
     */
    public function fetch(string $moduleId, string $version): string
    {
        $manifest = $this->requireManifest($moduleId);
        if (!$this->gitlab->configured()) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.not_configured'));
        }

        $version = GitLabModuleClient::normalizeVersion($version);
        if ($this->store->has($moduleId, $version)) {
            $this->store->ensureMeta($moduleId, $version, $this->store->pathFor($moduleId, $version));

            return $this->store->pathFor($moduleId, $version);
        }

        $zipDir = $this->store->root() . '/.tmp';
        if (!is_dir($zipDir) && !mkdir($zipDir, 0775, true) && !is_dir($zipDir)) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.mkdir'));
        }
        $zip = $zipDir . '/' . $moduleId . '-' . $version . '-' . bin2hex(random_bytes(4)) . '.zip';

        try {
            $this->gitlab->downloadArchive($manifest, $version, $zip);

            return $this->store->installFromZip($moduleId, $version, $zip);
        } finally {
            if (is_file($zip)) {
                @unlink($zip);
            }
        }
    }

    public function latestRemoteVersion(string $moduleId): ?string
    {
        $list = $this->listVersions($moduleId, false);
        foreach ($list as $row) {
            if (($row['tag'] ?? '') !== '' || ($row['version'] ?? '') !== '') {
                return $row['version'];
            }
        }

        return null;
    }

    /**
     * @return list<array{version: string, tag: string, committed_at: ?string}>
     */
    private function cachedTags(ModuleManifest $manifest, bool $refresh): array
    {
        $ttl = max(60, (int) $this->config->get('modules.gitlab.tags_cache_ttl', 900));
        $key = 'module_tags_' . md5($manifest->id . '|' . $this->gitlab->projectPathFor($manifest));

        if ($this->cache !== null && !$refresh) {
            $item = $this->cache->getItem($key);
            if ($item->isHit() && is_array($item->get())) {
                /** @var list<array{version: string, tag: string, committed_at: ?string}> $hit */
                $hit = $item->get();

                return $hit;
            }
        }

        $tags = $this->gitlab->listTags($manifest);

        if ($this->cache !== null) {
            $item = $this->cache->getItem($key);
            $item->set($tags);
            $item->expiresAfter($ttl);
            $this->cache->save($item);
        }

        return $tags;
    }

    private function requireManifest(string $moduleId): ModuleManifest
    {
        $manifest = $this->modules->manifest($moduleId);
        if ($manifest === null) {
            throw new \RuntimeException($this->translator->trans('modules.error.not_found', ['%id%' => $moduleId]));
        }

        return $manifest;
    }
}
