<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class NotificationTopbarTest extends TestCase
{
    public function testEmptyStateHasAnAccessibleNameWithoutACounter(): void
    {
        $html = $this->render(0, 'en');
        self::assertStringContainsString('aria-label="Notifications"', $html);
        self::assertStringNotContainsString('grq-topbar-notifications__count', $html);
        self::assertStringContainsString('href="/admin/notifications?x=&quot;quoted&quot;"', $html);
    }

    public function testUnreadCounterIsCappedVisuallyButAccessibleCountIsExact(): void
    {
        $html = $this->render(124, 'en');
        self::assertStringContainsString('aria-label="Notifications — Unread: 124"', $html);
        self::assertStringContainsString('aria-hidden="true">99+</span>', $html);
        self::assertStringNotContainsString('nav-item__badge', $html);
    }

    public function testCzechLabelAndSmallCount(): void
    {
        $html = $this->render(3, 'cs');
        self::assertStringContainsString('aria-label="Notifikace — Nepřečtené: 3"', $html);
        self::assertStringContainsString('aria-hidden="true">3</span>', $html);
    }

    private function render(int $count, string $locale): string
    {
        $root = dirname(__DIR__, 3);
        $twig = new Environment(new FilesystemLoader($root . '/resources/views'));
        $twig->addFunction(new TwigFunction('icon', static fn (): string => ''));
        $messages = require $root . '/resources/translations/messages.' . $locale . '.php';
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $parameters = []): string => strtr($messages[$key] ?? $key, $parameters)));
        return $twig->render('notifications/_topbar_button.html.twig', [
            'url' => '/admin/notifications?x="quoted"',
            'count' => $count,
            'bellIcon' => '<svg class="g-icon" viewBox="0 0 24 24"></svg>',
        ]);
    }
}
