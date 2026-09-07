<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\ESign;

use Modules\ESign\Domain\SignableDocument;
use Modules\ESign\Domain\SigningLink;
use PHPUnit\Framework\TestCase;

final class ESignDomainTest extends TestCase
{
    public function testSigningLinkUsability(): void
    {
        $link = new SigningLink(
            'doc-1',
            hash('sha256', 'token'),
            'Client@Example.com',
            new \DateTimeImmutable('+1 day'),
        );

        self::assertSame('client@example.com', $link->email());
        self::assertTrue($link->isUsable());

        $link->markUsed();
        self::assertFalse($link->isUsable());

        $fresh = new SigningLink('doc-1', 'hash', 'a@b.cz', new \DateTimeImmutable('+1 day'));
        $fresh->revoke();
        self::assertFalse($fresh->isUsable());

        $expired = new SigningLink('doc-1', 'hash', 'a@b.cz', new \DateTimeImmutable('-1 minute'));
        self::assertFalse($expired->isUsable());
    }

    public function testDocumentLockSignCancelAndReopen(): void
    {
        $doc = new SignableDocument('ES-1', 'Smlouva', '<p>text</p>', 'Klient');
        self::assertFalse($doc->isLocked());
        self::assertFalse($doc->isFullySigned());

        $doc->lock('<p>locked</p>', hash('sha256', 'locked'));
        self::assertTrue($doc->isLocked());
        self::assertSame(SignableDocument::STATUS_SENT, $doc->status());

        $this->expectException(\RuntimeException::class);
        $doc->setTitle('Nelze');
    }

    public function testMarkSignedAndCancel(): void
    {
        $doc = new SignableDocument('ES-2', 'Smlouva', '<p>text</p>', 'Klient');
        $doc->lock('<p>locked</p>', 'abc');
        $doc->markSigned('<p>signed</p>');
        self::assertTrue($doc->isFullySigned());
        self::assertNotNull($doc->signedAt());

        $doc->cancelSigning();
        self::assertSame(SignableDocument::STATUS_CANCELLED, $doc->status());
        self::assertFalse($doc->isLocked());

        $doc->reopenDraft();
        self::assertSame(SignableDocument::STATUS_DRAFT, $doc->status());
        $doc->setTitle('Upraveno');
        self::assertSame('Upraveno', $doc->title());
    }
}
