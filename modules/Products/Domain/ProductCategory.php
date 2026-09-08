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
#[ORM\Table(name: 'product_categories')]
#[FormEntity('products.category.save')]
#[FormGenerator(
    exclude: ['id', 'products'],
    form: 'products.category.save',
    cols: 1,
)]
class ProductCategory
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(length: 120)]
    #[FormField(required: true, label: 'products.categories.field.name', order: 10)]
    private string $name;
    #[ORM\Column(length: 120, unique: true)]
    #[FormField(label: 'products.categories.field.slug', hint: 'products.categories.field.slug.hint', order: 20)]
    private string $slug;
    /** @var Collection<int, Product> */
    #[ORM\ManyToMany(targetEntity: Product::class, mappedBy: 'categories')]
    private Collection $products;

    public function __construct(string $name, string $slug)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->name = $name;
        $this->slug = $slug;
        $this->products = new ArrayCollection();
    }
    public function id(): string { return $this->id; }
    public function name(): string { return $this->name; }
    public function slug(): string { return $this->slug; }
    /** @return Collection<int, Product> */
    public function products(): Collection { return $this->products; }
    public function setName(string $name): void { $this->name = $name; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
}
