<?php

declare(strict_types=1);

namespace Modules\Gereq\Application\Security;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Cache\AppCache;
use Modules\Gereq\Domain\SecurityEvent;
use Modules\Gereq\Domain\SecuritySite;
use Symfony\Component\HttpFoundation\Request;

final class BotScoreService
{
    public const BAN_TTL_SECONDS = 900;

    public function __construct(
        private readonly SecuritySiteService $sites,
        private readonly AppCache $cache,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, mixed> $signals
     * @return array{score: int, action: string, reasons: list<string>, visitor: string, banned: bool}
     */
    public function evaluate(SecuritySite $site, Request $request, array $signals = [], bool $forForm = false): array
    {
        $reasons = [];
        $score = 0;
        $ua = (string) $request->headers->get('User-Agent', '');

        if ($this->isBotUa($ua)) {
            $score += 70;
            $reasons[] = 'bot_ua';
        }
        if (($signals['webdriver'] ?? false) === true) {
            $score += 40;
            $reasons[] = 'webdriver';
        }
        if (!array_key_exists('plugins', $signals) || (int) $signals['plugins'] === 0) {
            $score += 15;
            $reasons[] = 'no_plugins';
        }
        $timeToInteract = $signals['tti_ms'] ?? null;
        $ttiOk = is_numeric($timeToInteract) && (int) $timeToInteract >= 400;
        if (!is_numeric($timeToInteract)) {
            $score += 25;
            $reasons[] = 'missing_tti';
        } elseif ((int) $timeToInteract < 400) {
            $score += 25;
            $reasons[] = 'too_fast';
        }
        $hadPointer = ($signals['had_pointer'] ?? false) === true;
        $hadScroll = ($signals['had_scroll'] ?? false) === true;
        $hadKeydown = ($signals['had_keydown'] ?? false) === true;
        if (!$hadPointer && !$hadScroll && !$hadKeydown) {
            $score += 30;
            $reasons[] = 'no_interaction';
        }
        if (($signals['honeypot_touched'] ?? false) === true) {
            $score += 50;
            $reasons[] = 'honeypot';
        }
        if ($forForm && $score === 0 && !$ttiOk) {
            $score += 20;
            $reasons[] = 'weak_trust';
        }

        $score = min(100, $score);
        $visitor = $this->sites->visitorHash($request);
        $action = $forForm
            ? $this->resolveFormAction($site->protectionMode(), $score)
            : $this->resolveAction($site->protectionMode(), $score);
        $banned = false;
        if ($action === 'block') {
            $this->cache->put($site->banCacheKey($visitor), true, self::BAN_TTL_SECONDS);
            $this->cache->put($site->ipBanCacheKey((string) $request->getClientIp()), true, self::BAN_TTL_SECONDS);
            $banned = true;
        }

        return [
            'score' => $score,
            'action' => $action,
            'reasons' => $reasons,
            'visitor' => $visitor,
            'banned' => $banned,
        ];
    }

    /** @param array{score: int, action: string, reasons: list<string>, visitor: string} $result */
    public function logEvent(SecuritySite $site, Request $request, array $result, ?string $path = null): SecurityEvent
    {
        $ua = (string) $request->headers->get('User-Agent', '');
        if (mb_strlen($ua) > 255) {
            $ua = mb_substr($ua, 0, 255);
        }
        $event = new SecurityEvent(
            $site->id(),
            $result['visitor'],
            $result['score'],
            $result['action'],
            $result['reasons'],
            $path,
            $ua !== '' ? $ua : null,
        );
        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    public function isBanned(SecuritySite $site, ?string $visitor, ?string $clientIp = null): bool
    {
        if (is_string($visitor) && $visitor !== '' && $this->cache->get($site->banCacheKey($visitor)) !== null) {
            return true;
        }
        if (is_string($clientIp) && $clientIp !== '' && $this->cache->get($site->ipBanCacheKey($clientIp)) !== null) {
            return true;
        }

        return false;
    }

    private function resolveAction(string $mode, int $score): string
    {
        $suspicious = $score >= 50;

        return match ($mode) {
            'challenge' => $suspicious ? 'challenge' : 'allow',
            'block' => $suspicious ? 'block' : 'allow',
            default => 'allow',
        };
    }

    private function resolveFormAction(string $mode, int $score): string
    {
        $suspicious = $score >= 50;
        if ($mode === 'block') {
            return $suspicious ? 'block' : 'allow';
        }

        return $suspicious ? 'challenge' : 'allow';
    }

    private function isBotUa(string $ua): bool
    {
        if ($ua === '') {
            return true;
        }
        $ua = strtolower($ua);
        foreach (['bot', 'crawl', 'spider', 'slurp', 'curl/', 'wget/', 'python-requests', 'httpclient'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }
}
