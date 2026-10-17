<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\User;

use Gereq\Testing\TestCase;
use Gereq\User\Entity\User;

final class UserUiPreferencesTest extends TestCase
{
    public function testMergeNavGroupsAndModules(): void
    {
        $user = new User('Demo', 'demo@example.com', 'hash');
        $user->mergeUiPreferences([
            'nav_groups' => ['E-Shop' => true, 'Systém' => false],
            'modules_expanded' => ['shop', 'webplus', 'shop'],
        ]);

        $prefs = $user->uiPreferences();
        self::assertTrue($prefs['nav_groups']['E-Shop']);
        self::assertFalse($prefs['nav_groups']['Systém']);
        self::assertSame(['shop', 'webplus'], $prefs['modules_expanded']);

        $user->mergeUiPreferences([
            'nav_groups' => ['E-Shop' => false],
            'nav_trees' => ['shop' => true],
        ]);
        $prefs = $user->uiPreferences();
        self::assertFalse($prefs['nav_groups']['E-Shop']);
        self::assertFalse($prefs['nav_groups']['Systém']);
        self::assertTrue($prefs['nav_trees']['shop']);
        self::assertSame(['shop', 'webplus'], $prefs['modules_expanded']);
    }

    public function testIgnoresInvalidValues(): void
    {
        $user = new User('Demo', 'demo@example.com', 'hash');
        $user->mergeUiPreferences([
            'nav_groups' => ['' => true, 'ok' => 'yes', 1 => true],
            'modules_expanded' => [null, '', 12, 'fleet'],
        ]);
        $prefs = $user->uiPreferences();
        self::assertArrayNotHasKey('nav_groups', $prefs);
        self::assertSame(['fleet'], $prefs['modules_expanded']);
    }
}
