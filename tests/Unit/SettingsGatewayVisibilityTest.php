<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Http\SettingsController;
use Gereq\Config\Repository;
use Gereq\Module\CapabilityRegistry;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsGatewayVisibilityTest extends TestCase
{
    public function testGatewaySettingsFollowPaymentsCapabilityAndReplacementScreen(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $registry = new PublishableRegistry();
        $settings = new Settings($em, new Repository(), new ContentReadContext(), $registry, new StagingWriter(new StagingStore($em), $registry));
        $settings->define('payments.stripe_secret', SettingRecord::TYPE_ENCRYPTED, 'stripe.secret');
        $capabilities = new CapabilityRegistry();
        $reflection = new \ReflectionClass(SettingsController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('settings')->setValue($controller, $settings);
        $reflection->getProperty('capabilities')->setValue($controller, $capabilities);
        $method = $reflection->getMethod('globalSettings');

        self::assertArrayNotHasKey('payments.stripe_secret', $method->invoke($controller));
        $capabilities->register('payments', 'payments', new \stdClass());
        self::assertArrayHasKey('payments.stripe_secret', $method->invoke($controller));
        $capabilities->register('payments.settings', 'payments', new \stdClass());
        self::assertArrayNotHasKey('payments.stripe_secret', $method->invoke($controller));
    }
}
