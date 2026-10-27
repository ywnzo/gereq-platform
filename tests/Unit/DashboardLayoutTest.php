<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Dashboard\DashboardWidget;
use Gereq\Config\Repository;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\User\Entity\User;
use Gereq\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DashboardLayoutTest extends TestCase
{
    public function testSavePreservesUnavailableWidgetPreferences(): void
    {
        [$dashboard, $record] = $this->dashboard();
        $dashboard->saveVisibleLayout([['id' => 'visible', 'enabled' => false, 'order' => 10, 'width' => 'third']], new User('Editor', 'editor@example.test', 'hash'));
        $saved = json_decode((string) $record->value(), true);
        self::assertContains(['id' => 'unavailable', 'enabled' => false, 'order' => 20, 'width' => 'full'], $saved);
        self::assertContains(['id' => 'visible', 'enabled' => false, 'order' => 10, 'width' => 'third'], $saved);
    }

    public function testSaveRejectsUnauthorizedWidget(): void
    {
        [$dashboard] = $this->dashboard();
        $this->expectException(ValidationException::class);
        $dashboard->saveVisibleLayout([['id' => 'restricted', 'enabled' => true, 'order' => 1, 'width' => 'half']], new User('Editor', 'editor@example.test', 'hash'));
    }

    /** @return array{Dashboard, SettingRecord} */
    private function dashboard(): array
    {
        $record = new SettingRecord('admin.dashboard.layout', json_encode([
            ['id' => 'unavailable', 'enabled' => false, 'order' => 20, 'width' => 'full'],
        ], JSON_THROW_ON_ERROR), SettingRecord::TYPE_JSON);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([$record]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('find')->willReturn($record);
        $publishables = new PublishableRegistry();
        $settings = new Settings($em, new Repository([]), new ContentReadContext(), $publishables, new StagingWriter(new StagingStore($em), $publishables));
        $dashboard = new Dashboard($settings, $this->createMock(TranslatorInterface::class));
        foreach (['visible' => null, 'restricted' => 'secret.view'] as $id => $permission) {
            $dashboard->addWidget(new class ($id, $permission) implements DashboardWidget {
                public function __construct(private readonly string $key, private readonly ?string $right) {}
                public function id(): string { return $this->key; }
                public function title(): string { return $this->key; }
                public function type(): string { return self::TYPE_PANEL; }
                public function render(): string { return ''; }
                public function permission(): ?string { return $this->right; }
                public function order(): int { return 10; }
            });
        }

        return [$dashboard, $record];
    }
}
