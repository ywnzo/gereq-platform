<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Media;

use Gereq\Event\EventBus;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookManager;
use Modules\Media\Domain\Events\MediaDeleted;
use Modules\Media\Domain\Events\MediaUpdated;
use Modules\Media\Domain\Events\MediaUploaded;
use Modules\Webhooks\Domain\Webhook;
use PHPUnit\Framework\TestCase;

final class MediaEventsTest extends TestCase
{
    public function testUploadedEnvelopeShapeAndWebhookPayload(): void
    {
        $data = [
            'id' => 'asset-1',
            'title' => 'Hero',
            'mime' => 'image/avif',
            'url' => '/media/asset-1/file',
        ];
        $event = MediaUploaded::make('asset-1', $data, ['type' => 'user', 'id' => 'u1']);

        self::assertSame('media.uploaded', $event->type());
        self::assertSame($data, $event->data());

        $envelope = $event->envelope();
        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertSame('media.uploaded', $envelope->type);
        self::assertSame('1', $envelope->version);
        self::assertSame(['type' => 'media_asset', 'id' => 'asset-1'], $envelope->resource);
        self::assertSame(['type' => 'user', 'id' => 'u1'], $envelope->actor);
        self::assertStringStartsWith('evt_', $envelope->id);

        $payload = $envelope->webhookPayload();
        self::assertSame('media.uploaded', $payload['type']);
        self::assertSame($data, $payload['data']);
        self::assertArrayHasKey('created_at', $payload);
        self::assertArrayNotHasKey('resource', $payload);
    }

    public function testUpdatedAndDeletedTypes(): void
    {
        self::assertSame('media.updated', MediaUpdated::make('a')->type());
        self::assertSame('media.deleted', MediaDeleted::make('a')->type());
        self::assertSame('media_asset', MediaDeleted::make('a')->envelope()->resource['type']);
    }

    public function testDispatchFiresPlatformHook(): void
    {
        $hooks = new HookManager();
        $seen = [];
        $hooks->addAction('platform.event.dispatched', static function (EventEnvelope $envelope, object $event) use (&$seen): void {
            $seen[] = [
                'type' => $envelope->type,
                'class' => $event::class,
                'webhook' => $envelope->webhookPayload(),
            ];
        });

        $bus = new EventBus($hooks);
        $bus->dispatch(MediaUploaded::make('a1', ['id' => 'a1']));
        $bus->dispatch(MediaUpdated::make('a1', ['id' => 'a1', 'title' => 'X']));
        $bus->dispatch(MediaDeleted::make('a1', ['id' => 'a1']));

        self::assertCount(3, $seen);
        self::assertSame(['media.uploaded', 'media.updated', 'media.deleted'], array_column($seen, 'type'));
        self::assertSame(MediaUploaded::class, $seen[0]['class']);
        self::assertSame('media.uploaded', $seen[0]['webhook']['type']);
    }

    public function testWebhookPatternsMatchMediaEvents(): void
    {
        $exact = new Webhook('Media exact', 'https://example.com/hook', ['media.uploaded']);
        $wildcard = new Webhook('Media wild', 'https://example.com/hook', ['media.*']);
        $all = new Webhook('All', 'https://example.com/hook', ['*']);
        $other = new Webhook('Orders', 'https://example.com/hook', ['order.*']);

        foreach (['media.uploaded', 'media.updated', 'media.deleted'] as $type) {
            self::assertTrue($wildcard->matchesEvent($type), $type);
            self::assertTrue($all->matchesEvent($type), $type);
            self::assertFalse($other->matchesEvent($type), $type);
        }

        self::assertTrue($exact->matchesEvent('media.uploaded'));
        self::assertFalse($exact->matchesEvent('media.updated'));
    }

    public function testMediaModuleRegistersWebhookEnabledEvents(): void
    {
        $moduleFile = dirname(__DIR__, 4) . '/modules/Media/MediaModule.php';
        $source = (string) file_get_contents($moduleFile);

        self::assertStringContainsString("'media.uploaded'", $source);
        self::assertStringContainsString("'media.updated'", $source);
        self::assertStringContainsString("'media.deleted'", $source);
        self::assertStringContainsString('->webhookEnabled(true)', $source);
        self::assertStringContainsString("registerKnownScope('media.read')", $source);
        self::assertStringContainsString("registerKnownScope('media.write')", $source);
        self::assertStringContainsString("capabilities()->register('media'", $source);
    }
}
