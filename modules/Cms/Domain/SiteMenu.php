<?php

declare(strict_types=1);

namespace Modules\Cms\Domain;

use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cms_site_menus')]
#[FormEntity('cms.menu.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt'],
    form: 'cms.menu.save',
    cols: 1,
)]
class SiteMenu
{
    public const LOCATIONS = ['header', 'footer'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 120)]
    #[FormField(required: true, label: 'cms.field.name', order: 10)]
    private string $name;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'cms.field.location',
        type: 'select',
        choicesFrom: 'LOCATIONS',
        choiceLabel: 'cms.menu.location.',
        order: 20,
    )]
    private string $location = 'header';

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $location = 'header')
    {
        if (!in_array($location, self::LOCATIONS, true)) {
            throw new \InvalidArgumentException('cms.error.invalid_menu_location');
        }
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->location = $location;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function locationLabel(): string
    {
        return 'cms.menu.location.' . $this->location;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function setLocation(string $location): void
    {
        if (!in_array($location, self::LOCATIONS, true)) {
            throw new \InvalidArgumentException('cms.error.invalid_menu_location');
        }
        $this->location = $location;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
