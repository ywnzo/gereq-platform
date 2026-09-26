<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Gereq\Config\Repository;
use Gereq\Publish\ContentReadContext;
use Gereq\Publish\PublishableRegistry;
use Gereq\Publish\StagingStore;
use Gereq\Publish\StagingWriter;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Setup\SetupState;
use PHPUnit\Framework\TestCase;

final class SettingsCacheTest extends TestCase
{
    public function testGetLoadsAllSettingsOnce(): void
    {
        $rows = [
            new SettingRecord('brand.name', 'GEREQ', SettingRecord::TYPE_STRING),
            new SettingRecord('app.locale', 'cs', SettingRecord::TYPE_STRING),
            new SettingRecord('setup.completed', '1', SettingRecord::TYPE_BOOLEAN),
        ];

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(SettingRecord::class)->willReturn($repo);

        $settings = $this->settings($em);

        self::assertSame('GEREQ', $settings->get('brand.name'));
        self::assertSame('cs', $settings->get('app.locale'));
        self::assertTrue($settings->get('setup.completed'));
        self::assertTrue($settings->has('brand.name'));
        self::assertFalse($settings->has('missing.key'));
        self::assertSame('fallback', $settings->get('missing.key', 'fallback'));
    }

    public function testSetupStateUsesSettingsWithoutExtraFind(): void
    {
        $rows = [
            new SettingRecord(SetupState::KEY, '1', SettingRecord::TYPE_BOOLEAN, 'core'),
        ];

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('findAll')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(SettingRecord::class)->willReturn($repo);
        $em->expects(self::never())->method('find');

        $settings = $this->settings($em);
        $state = new SetupState($settings);

        self::assertTrue($state->isComplete());
        self::assertFalse($state->isPending());
    }

    public function testSetupStateLegacyWithoutFlagIsComplete(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findAll')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $state = new SetupState($this->settings($em));

        self::assertTrue($state->isComplete());
    }

    private function settings(EntityManagerInterface $em): Settings
    {
        $publishables = new PublishableRegistry();

        return new Settings(
            $em,
            new Repository([]),
            new ContentReadContext(),
            $publishables,
            new StagingWriter(new StagingStore($em), $publishables),
        );
    }
}
