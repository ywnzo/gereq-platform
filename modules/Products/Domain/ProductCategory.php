<?php
declare(strict_types=1);
namespace Modules\Products\Domain;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'product_categories')]
class ProductCategory
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;
    #[ORM\Column(length: 120)]
    private string $name;
    #[ORM\Column(length: 120, unique: true)]
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