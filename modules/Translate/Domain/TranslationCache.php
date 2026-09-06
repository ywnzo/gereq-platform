<?php

declare(strict_types=1);

namespace Modules\Translate\Domain;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'translate_cache')]
#[ORM\UniqueConstraint(name: 'uniq_translate_hash', columns: ['hash'])]
#[ORM\Index(name: 'idx_translate_langs', columns: ['source_locale', 'target_locale'])]
class TranslationCache
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $hash;

    #[ORM\Column(name: 'source_locale', length: 8)]
    private string $sourceLocale;

    #[ORM\Column(name: 'target_locale', length: 8)]
    private string $targetLocale;

    #[ORM\Column(name: 'source_text', type: 'text')]
    private string $sourceText;

    #[ORM\Column(name: 'translated_text', type: 'text')]
    private string $translatedText;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $sourceLocale,
        string $targetLocale,
        string $sourceText,
        string $translatedText,
    ) {
        $this->id = Uuid::uuid7()->toString();
        $this->sourceLocale = $sourceLocale;
        $this->targetLocale = $targetLocale;
        $this->sourceText = $sourceText;
        $this->translatedText = $translatedText;
        $this->hash = self::makeHash($sourceLocale, $targetLocale, $sourceText);
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function makeHash(string $sourceLocale, string $targetLocale, string $sourceText): string
    {
        return hash('sha256', $sourceLocale . '|' . $targetLocale . '|' . $sourceText);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function sourceLocale(): string
    {
        return $this->sourceLocale;
    }

    public function targetLocale(): string
    {
        return $this->targetLocale;
    }

    public function sourceText(): string
    {
        return $this->sourceText;
    }

    public function translatedText(): string
    {
        return $this->translatedText;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setTranslatedText(string $text): void
    {
        $this->translatedText = $text;
    }
}
