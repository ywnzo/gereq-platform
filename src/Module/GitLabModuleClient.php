<?php

declare(strict_types=1);

namespace Gereq\Module;

use Gereq\Config\Repository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GitLab API client for module tags + repository archives.
 */
final class GitLabModuleClient
{
    public function __construct(
        private readonly Repository $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function configured(): bool
    {
        return $this->token() !== '' && $this->group() !== '';
    }

    public function projectPathFor(ModuleManifest $manifest): string
    {
        $override = trim($manifest->repository ?? '');
        if ($override !== '') {
            return ltrim($override, '/');
        }

        return $this->group() . '/' . $this->repoPrefix() . $manifest->id;
    }

    /**
     * @return list<array{version: string, tag: string, committed_at: ?string}>
     */
    public function listTags(ModuleManifest $manifest): array
    {
        $this->assertConfigured();
        $project = rawurlencode($this->projectPathFor($manifest));
        $page = 1;
        $out = [];

        while ($page <= 20) {
            $payload = $this->request('GET', '/api/v4/projects/' . $project . '/repository/tags', [
                'per_page' => '100',
                'page' => (string) $page,
                'order_by' => 'version',
                'sort' => 'desc',
            ]);
            if (!is_array($payload) || $payload === []) {
                break;
            }

            foreach ($payload as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $tag = trim((string) ($row['name'] ?? ''));
                $version = self::normalizeVersion($tag);
                if ($version === '' || !self::isSemver($version)) {
                    continue;
                }
                $committed = null;
                if (is_array($row['commit'] ?? null)) {
                    $committed = isset($row['commit']['committed_date'])
                        ? (string) $row['commit']['committed_date']
                        : null;
                }
                $out[] = [
                    'version' => $version,
                    'tag' => $tag,
                    'committed_at' => $committed,
                ];
            }

            if (count($payload) < 100) {
                break;
            }
            ++$page;
        }

        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        return $out;
    }

    /** Download repository archive for a tag into $destinationZip. */
    public function downloadArchive(ModuleManifest $manifest, string $tagOrVersion, string $destinationZip): void
    {
        $this->assertConfigured();
        $version = self::normalizeVersion($tagOrVersion);
        $tag = str_starts_with($tagOrVersion, 'v') ? $tagOrVersion : 'v' . $version;
        $project = rawurlencode($this->projectPathFor($manifest));
        $url = $this->baseUrl() . '/api/v4/projects/' . $project . '/repository/archive.zip?sha=' . rawurlencode($tag);

        $dir = dirname($destinationZip);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.mkdir'));
        }

        $tmp = $destinationZip . '.part';
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.write'));
        }

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.http'));
            }
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => [
                    'PRIVATE-TOKEN: ' . $this->token(),
                    'Accept: application/octet-stream',
                ],
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            $fh = null;

            if ($ok === false || $status < 200 || $status >= 300) {
                @unlink($tmp);
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.download', [
                    '%status%' => (string) $status,
                    '%detail%' => $err !== '' ? $err : 'HTTP ' . $status,
                ]));
            }

            if (!rename($tmp, $destinationZip)) {
                @unlink($tmp);
                throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.write'));
            }
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
    }

    public static function normalizeVersion(string $tagOrVersion): string
    {
        $v = trim($tagOrVersion);
        if (str_starts_with(strtolower($v), 'v') && preg_match('/^v\d/i', $v) === 1) {
            $v = substr($v, 1);
        }

        return $v;
    }

    public static function isSemver(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private function assertConfigured(): void
    {
        if (!$this->configured()) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.not_configured'));
        }
    }

    /** @param array<string, string> $query */
    private function request(string $method, string $path, array $query = []): mixed
    {
        $url = $this->baseUrl() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.http'));
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'PRIVATE-TOKEN: ' . $this->token(),
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($this->translator->trans('admin.modules.versions.error.http_status', [
                '%status%' => (string) $status,
                '%detail%' => $err !== '' ? $err : (is_string($body) ? mb_substr($body, 0, 200) : ''),
            ]));
        }

        return json_decode($body, true);
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->get('modules.gitlab.url', 'https://gitlab.com'), '/');
    }

    private function token(): string
    {
        return trim((string) $this->config->get('modules.gitlab.token', ''));
    }

    private function group(): string
    {
        return trim((string) $this->config->get('modules.gitlab.group', 'gereq'));
    }

    private function repoPrefix(): string
    {
        return (string) $this->config->get('modules.gitlab.repo_prefix', 'module-');
    }
}
