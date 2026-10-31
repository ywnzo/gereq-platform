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
    exclude: ['id', 'publishedAt', 'createdAt', 'updatedAt', 'menuSortOrder', 'menuLocations'],
    form: 'cms.page.save',
    cols: 1,
)]
class Page
{
    public const STATUSES = ['draft', 'published', 'archived'];
    public const TEMPLATES = ['default', 'landing'];
    public const CONTENT_MODES = ['blocks', 'raw'];
    public const MENU_LOCATIONS = ['header', 'footer', 'custom'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 180)]
    #[FormField(required: true, label: 'cms.field.slug', hint: 'cms.field.slug.hint', order: 20, group: 'content')]
    private string $slug;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'cms.field.title', order: 10, group: 'content')]
    private string $title;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'cms.field.status',
        type: 'select',
        choicesFrom: 'STATUSES',
        choiceLabel: 'cms.page.status.',
        order: 30,
        group: 'publishing',
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
        group: 'publishing',
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
        group: 'publishing',
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
        group: 'content',
    )]
    private ?string $body = null;

    #[ORM\Column(name: 'meta_description', type: 'text', nullable: true)]
    #[FormField(
        label: 'cms.field.meta_description',
        type: 'textarea',
        order: 70,
        group: 'content',
    )]
    private ?string $metaDescription = null;

    /**
     * When true, storefront first HTML includes a semantic body snapshot of this page
     * (bots / LCP / noscript). Vue still mounts and replaces #app.
     */
    #[ORM\Column(name: 'ssg', type: 'boolean', options: ['default' => false])]
    #[FormField(
        label: 'cms.field.ssg',
        type: 'checkbox',
        hint: 'cms.field.ssg.hint',
        order: 75,
        group: 'publishing',
    )]
    private bool $ssg = false;

    /** @var list<string> */
    #[ORM\Column(name: 'menu_locations', type: 'json')]
    private array $menuLocations = [];

    #[ORM\Column(name: 'menu_sort_order', options: ['default' => 0])]
    private int $menuSortOrder = 0;

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

    public function ssg(): bool
    {
        return $this->ssg;
    }

    /** @return list<string> */
    public function menuLocations(): array
    {
        return $this->menuLocations;
    }

    public function inMenu(string $location): bool
    {
        return in_array($location, $this->menuLocations, true);
    }

    public function menuSortOrder(): int
    {
        return $this->menuSortOrder;
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

    /** @return list<string> Translation keys for assigned menus */
    public function menuLocationLabels(): array
    {
        $labels = [];
        foreach ($this->menuLocations as $location) {
            $labels[] = 'cms.menu.location.' . $location;
        }

        return $labels;
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

    public function setSsg(bool $ssg): void
    {
        $this->ssg = $ssg;
        $this->touch();
    }

    /** @param list<string>|string|null $locations */
    public function setMenuLocations(array|string|null $locations): void
    {
        if (is_string($locations)) {
            $locations = $locations !== '' ? [$locations] : [];
        }
        if ($locations === null) {
            $locations = [];
        }

        $normalized = [];
        foreach ($locations as $location) {
            if (!is_string($location)) {
                continue;
            }
            $location = trim($location);
            if ($location === '' || !in_array($location, self::MENU_LOCATIONS, true)) {
                continue;
            }
            if (!in_array($location, $normalized, true)) {
                $normalized[] = $location;
            }
        }

        $this->menuLocations = $normalized;
        $this->touch();
    }

    public function setMenuSortOrder(int $order): void
    {
        $this->menuSortOrder = max(0, $order);
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
