<?php
declare(strict_types=1);
namespace Modules\Products\Domain;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gereq\Admin\Form\FormEntity;
use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[FormEntity('products.product.save')]
#[FormGenerator(
    exclude: ['id', 'createdAt', 'updatedAt', 'categories', 'inheritCategoryOptions'],
    form: 'products.product.save',
    cols: 2,
)]
class Product
{
    public const STATUSES = ['draft', 'active', 'archived'];
    public const NOTE_MODES = ['off', 'optional', 'required'];

    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64, unique: true)]
    #[FormField(required: true, label: 'products.field.sku', group: 'basic', order: 10)]
    private string $sku;

    #[ORM\Column(length: 190)]
    #[FormField(required: true, label: 'products.field.name', group: 'basic', order: 20)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    #[FormField(label: 'products.field.description', type: 'textarea', group: 'basic', extra: 'field--full', order: 50)]
    private ?string $description = null;

    #[ORM\Column(length: 32)]
    #[FormField(
        required: true,
        label: 'products.field.status',
        type: 'select',
        group: 'basic',
        choicesFrom: 'STATUSES',
        choiceLabel: 'products.status.',
        order: 40,
    )]
    private string $status = 'draft';

    #[ORM\Column(length: 32)]
    #[FormField(required: true, label: 'products.field.unit', group: 'basic', order: 30)]
    private string $unit = 'ks';

    /** off | optional | required — customer note on storefront line item */
    #[ORM\Column(name: 'customer_note_mode', length: 16, options: ['default' => 'off'])]
    #[FormField(
        label: 'products.field.customer_note_mode',
        type: 'select',
        group: 'personalization',
        choicesFrom: 'NOTE_MODES',
        choiceLabel: 'products.note_mode.',
        order: 10,
    )]
    private string $customerNoteMode = 'off';

    /** When true, option groups from the first category are included on the storefront / generator. */
    #[ORM\Column(name: 'inherit_category_options', type: 'boolean', options: ['default' => false])]
    private bool $inheritCategoryOptions = false;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ProductCategory> */
    #[ORM\ManyToMany(targetEntity: ProductCategory::class, inversedBy: 'products')]
    #[ORM\JoinTable(name: 'product_category_links')]
    private Collection $categories;

    public function __construct(string $sku, string $name)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->sku = strtoupper($sku);
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->categories = new ArrayCollection();
    }
    public function id(): string { return $this->id; }
    public function sku(): string { return $this->sku; }
    public function name(): string { return $this->name; }
    public function description(): ?string { return $this->description; }
    public function status(): string { return $this->status; }
    public function unit(): string { return $this->unit; }
    public function customerNoteMode(): string { return $this->customerNoteMode; }
    public function inheritsCategoryOptions(): bool { return $this->inheritCategoryOptions; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    /** @return Collection<int, ProductCategory> */
    public function categories(): Collection { return $this->categories; }
    public function setName(string $name): void { $this->name = $name; $this->touch(); }
    public function setDescription(?string $description): void { $this->description = $description; $this->touch(); }
    public function setStatus(string $status): void { $this->status = $status; $this->touch(); }
    public function setUnit(string $unit): void { $this->unit = $unit; $this->touch(); }
    public function setCustomerNoteMode(string $mode): void
    {
        $mode = strtolower(trim($mode));
        $this->customerNoteMode = in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'off';
        $this->touch();
    }
    public function setInheritCategoryOptions(bool $inherit): void
    {
        $this->inheritCategoryOptions = $inherit;
        $this->touch();
    }
    public function setSku(string $sku): void { $this->sku = strtoupper($sku); $this->touch(); }
    public function addCategory(ProductCategory $c): void { if (!$this->categories->contains($c)) $this->categories->add($c); }
    public function clearCategories(): void { $this->categories->clear(); }
    public function statusLabel(): string { return 'products.status.' . $this->status; }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
