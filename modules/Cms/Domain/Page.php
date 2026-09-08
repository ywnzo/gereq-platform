<?php

declare(strict_types=1);

namespace Modules\Cms\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cms_pages')]
#[ORM\UniqueConstraint(name: 'uniq_cms_pages_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_cms_pages_status', columns: ['status'])]
#[FormEntity('cms.page.save')]
#[FormGenerator(
    exclude: ['id', 'publishedAt', 'createdAt', 'updatedAt'],
    form: 'cms.page.save',
    cols: 1,
)]
class Page
{
    public const STATUSES = ['draft', 'published', 'archived'];
    public const TEMPLATES = ['default', 'landing'];
    public const CONTENT_MODES = ['blocks', 'raw'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 180)]
    #[FormField(required: true, label: 'cms.field.slug', hint: 'cms.field.slug.hint', order: 20)]
    private string $slug;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'cms.field.title', order: 10)]
    private string $title;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'cms.field.status',
        type: 'select',
        choicesFrom: 'STATUSES',
        choiceLabel: 'cms.page.status.',
        order: 30,
    )]
    private string $status = 'draft';

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'cms.field.template',
        type: 'select',
        choicesFrom: 'TEMPLATES',
        choiceLabel: 'cms.page.template.',
        order: 40,
    )]
    private string $template = 'default';

    /** blocks = PageBlock builder, raw = single textarea body */
    #[ORM\Column(name: 'content_mode', length: 16, options: ['default' => 'blocks'])]
    #[FormField(
        required: true,
        label: 'cms.field.content_mode',
        type: 'select',
        choicesFrom: 'CONTENT_MODES',
        choiceLabel: 'cms.page.content_mode.',
        hint: 'cms.field.content_mode.hint',
        order: 50,
    )]
    private string $contentMode = 'blocks';

    /** HTML/plain body when content_mode=raw */
    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(
        label: 'cms.field.body',
        type: 'textarea',
        hint: 'cms.field.body.hint',
        extra: 'field--full',
        order: 60,
    )]
    private ?string $body = null;

    #[ORM\Column(name: 'meta_description', type: 'text', nullable: true)]
    #[FormField(
        label: 'cms.field.meta_description',
        type: 'textarea',
        order: 70,
    )]
    private ?string $metaDescription = null;

    #[ORM\Column(name: 'published_at', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $title)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->slug = $this->normalizeSlug($slug);
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function template(): string
    {
        return $this->template;
    }

    public function contentMode(): string
    {
        return $this->contentMode;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    public function isRaw(): bool
    {
        return $this->contentMode === 'raw';
    }

    public function metaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function publishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function statusLabel(): string
    {
        return 'cms.page.status.' . $this->status;
    }

    public function templateLabel(): string
    {
        return 'cms.page.template.' . $this->template;
    }

    public function contentModeLabel(): string
    {
        return 'cms.page.content_mode.' . $this->contentMode;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $this->normalizeSlug($slug);
        $this->touch();
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('cms.error.invalid_status');
        }
        $wasPublished = $this->status === 'published';
        $this->status = $status;
        if ($status === 'published' && !$wasPublished) {
            $this->publishedAt = new \DateTimeImmutable();
        }
        if ($status !== 'published') {
            // keep publishedAt for history when unpublishing
        }
        $this->touch();
    }

    public function setTemplate(string $template): void
    {
        if (!in_array($template, self::TEMPLATES, true)) {
            throw new \InvalidArgumentException('cms.error.invalid_template');
        }
        $this->template = $template;
        $this->touch();
    }

    public function setContentMode(string $mode): void
    {
        if (!in_array($mode, self::CONTENT_MODES, true)) {
            throw new \InvalidArgumentException('cms.error.invalid_content_mode');
        }
        $this->contentMode = $mode;
        $this->touch();
    }

    public function setBody(?string $body): void
    {
        $trimmed = $body !== null ? trim($body) : '';
        $this->body = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function setMetaDescription(?string $metaDescription): void
    {
        $this->metaDescription = $metaDescription !== null && $metaDescription !== '' ? $metaDescription : null;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('~[^a-z0-9]+~', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'page';
    }
}
