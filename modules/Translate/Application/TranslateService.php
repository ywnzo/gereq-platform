<?php

declare(strict_types=1);

namespace Modules\Translate\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Setting\Settings;
use Modules\Translate\Domain\TranslationCache;

final class TranslateService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Settings $settings,
        private readonly TranslateProviderInterface $provider,
    ) {
    }

    public function isConfigured(): bool
    {
        $ok = $this->provider->isConfigured();
        bdump([
            'configured' => $ok,
            'provider' => $this->provider::class,
        ], 'translate.isConfigured');

        return $ok;
    }

    /**
     * @return array{ok: bool, message: string, details?: array<string, mixed>}
     */
    public function ping(): array
    {
        return $this->provider->ping();
    }

    public function sourceLocale(): string
    {
        return trim((string) $this->settings->get('translate.source_locale', 'ru')) ?: 'ru';
    }

    public function targetLocale(): string
    {
        return trim((string) $this->settings->get('translate.target_locale', 'cs')) ?: 'cs';
    }

    public function cacheCount(): int
    {
        return (int) $this->em->getRepository(TranslationCache::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function clearCache(): int
    {
        $n = $this->cacheCount();
        $this->em->createQuery('DELETE FROM ' . TranslationCache::class . ' c')->execute();

        return $n;
    }

    public function translate(string $text, ?string $sourceLocale = null, ?string $targetLocale = null): string
    {
        bdump(['text' => $text, 'from' => $sourceLocale, 'to' => $targetLocale], 'translate.translate.in');
        $batch = $this->batch([$text], $sourceLocale, $targetLocale);
        $out = $batch[0] ?? $text;
        bdump($out, 'translate.translate.out');

        return $out;
    }

    /**
     * Instant: jen DB cache. Bez API, bez delay.
     * Miss = původní text (typicky ruština).
     *
     * @param list<string> $texts
     * @return list<string>
     */
    public function batchInstant(array $texts, ?string $sourceLocale = null, ?string $targetLocale = null): array
    {
        $from = $sourceLocale ?? $this->sourceLocale();
        $to = $targetLocale ?? $this->targetLocale();

        if ($texts === [] || $from === $to) {
            return $texts;
        }

        $plan = $this->plan($texts, $from, $to);
        bdump([
            'mode' => 'instant',
            'skipped' => $plan['skipped'],
            'cacheHits' => count($plan['hits']),
            'misses' => count($plan['pendingTexts']),
        ], 'translate.batchInstant');

        $result = $plan['result'];
        foreach ($plan['hits'] as $i => $translated) {
            $result[$i] = $translated;
        }
        ksort($result);

        return array_values($result);
    }

    /**
     * Cache + provider (Microsoft Translator). Pro admin test / warm — ne pro request katalogu.
     *
     * @param list<string> $texts
     * @return list<string>
     */
    public function batch(array $texts, ?string $sourceLocale = null, ?string $targetLocale = null): array
    {
        $from = $sourceLocale ?? $this->sourceLocale();
        $to = $targetLocale ?? $this->targetLocale();
        bdump([
            'count' => count($texts),
            'from' => $from,
            'to' => $to,
            'sample' => array_slice($texts, 0, 5),
        ], 'translate.batch.start');

        if ($texts === [] || $from === $to) {
            bdump('skip: empty or same locale', 'translate.batch.skip');

            return $texts;
        }

        $plan = $this->plan($texts, $from, $to);
        bdump([
            'skipped' => $plan['skipped'],
            'cacheHits' => count($plan['hits']),
            'pending' => count($plan['pendingTexts']),
            'pendingSample' => array_slice($plan['pendingTexts'], 0, 8),
        ], 'translate.batch.plan');

        $result = $plan['result'];
        foreach ($plan['hits'] as $i => $translated) {
            $result[$i] = $translated;
        }

        if ($plan['pendingTexts'] !== []) {
            bdump('calling provider…', 'translate.batch.provider');
            try {
                $translated = $this->provider->translateBatch($plan['pendingTexts'], $from, $to);
                bdump([
                    'returned' => count($translated),
                    'sample' => array_slice($translated, 0, 5),
                ], 'translate.batch.provider.ok');
            } catch (\Throwable $e) {
                bdump([
                    'class' => $e::class,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ], 'translate.batch.provider.error');
                throw $e;
            }
            foreach ($plan['pendingTexts'] as $j => $src) {
                $dst = $translated[$j] ?? $src;
                foreach ($plan['pendingSlots'][$j] as $i) {
                    $result[$i] = $dst;
                }
                $this->em->persist(new TranslationCache($from, $to, $src, $dst));
            }
            $this->em->flush();
            bdump('cache flushed', 'translate.batch.cache');
        }

        ksort($result);

        return array_values($result);
    }

    /**
     * @param list<string> $texts
     * @return array{
     *   result: array<int, string>,
     *   hits: array<int, string>,
     *   pendingTexts: list<string>,
     *   pendingSlots: list<list<int>>,
     *   skipped: int
     * }
     */
    private function plan(array $texts, string $from, string $to): array
    {
        $result = [];
        /** @var array<string, list<int>> $needLookup */
        $needLookup = [];
        /** @var array<string, string> $hashToSource */
        $hashToSource = [];
        $skipped = 0;

        foreach (array_values($texts) as $i => $text) {
            $trimmed = trim($text);
            if ($trimmed === '' || !$this->needsTranslation($trimmed, $to)) {
                $result[$i] = $text;
                ++$skipped;
                continue;
            }
            $hash = TranslationCache::makeHash($from, $to, $trimmed);
            $needLookup[$hash][] = $i;
            $hashToSource[$hash] = $trimmed;
            $result[$i] = $text;
        }

        /** @var array<string, string> $byHash */
        $byHash = [];
        if ($needLookup !== []) {
            /** @var list<TranslationCache> $rows */
            $rows = $this->em->getRepository(TranslationCache::class)
                ->createQueryBuilder('c')
                ->where('c.hash IN (:hashes)')
                ->setParameter('hashes', array_keys($needLookup))
                ->getQuery()
                ->getResult();
            foreach ($rows as $row) {
                if ($row instanceof TranslationCache) {
                    $byHash[$row->hash()] = $row->translatedText();
                }
            }
        }

        $hits = [];
        $pendingTexts = [];
        /** @var list<list<int>> $pendingSlots */
        $pendingSlots = [];

        foreach ($needLookup as $hash => $indices) {
            if (isset($byHash[$hash])) {
                foreach ($indices as $i) {
                    $hits[$i] = $byHash[$hash];
                }
                continue;
            }
            $pendingTexts[] = $hashToSource[$hash];
            $pendingSlots[] = $indices;
        }

        return [
            'result' => $result,
            'hits' => $hits,
            'pendingTexts' => $pendingTexts,
            'pendingSlots' => $pendingSlots,
            'skipped' => $skipped,
        ];
    }

    private function needsTranslation(string $text, string $targetLocale): bool
    {
        if (preg_match('/^[\d\s\-.,\/+#A-Za-z]+$/u', $text) === 1) {
            return false;
        }
        if (preg_match('/\p{Cyrillic}/u', $text) === 1) {
            return true;
        }
        if ($targetLocale === 'cs' && preg_match('/[а-яА-ЯёЁ]/u', $text) === 1) {
            return true;
        }

        return preg_match('/\p{Cyrillic}/u', $text) === 1;
    }
}
