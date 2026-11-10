<?php

declare(strict_types=1);

namespace App\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Custom SKU entity mapped to the `custom_skus` table.
 *
 * A custom SKU is a bundle of products sold under its own SKU; its products
 * and quantities are held in `custom_sku_products`.
 *
 * Decimal columns are exposed as strings, as Doctrine maps DECIMAL to string
 * to avoid floating point precision loss.
 */
#[ORM\Entity]
#[ORM\Table(name: "custom_skus")]
#[ORM\UniqueConstraint(name: "sku_unique", columns: ["sku"])]
#[ORM\Index(name: "IDX_867E916764C19C1", columns: ["category"])]
class CustomSku
{
    /**
     * Status of a custom SKU that is in use.
     */
    public const STATUS_ACTIVE = 1;

    /**
     * Status of a custom SKU that is no longer in use.
     */
    public const STATUS_INACTIVE = 2;

    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Category this custom SKU belongs to (`category` column, references `categories.id`).
     */
    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: "category", referencedColumnName: "id")]
    private ?Category $category = null;

    /**
     * Stock keeping unit, unique across custom SKUs.
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $sku;

    /**
     * Shipping service name.
     */
    #[ORM\Column(name: "shipping_service", type: Types::STRING, length: 191, nullable: true)]
    private ?string $shippingService = null;

    /**
     * Postage cost.
     */
    #[ORM\Column(name: "postage_cost", type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $postageCost;

    /**
     * Packaging cost.
     */
    #[ORM\Column(name: "package_cost", type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $packageCost;

    /**
     * Status: 1 = active, 2 = inactive (see the STATUS_* constants).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ["default" => 1])]
    private ?int $status = 1;

    /**
     * Creation timestamp.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    /**
     * Last update timestamp.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $updated = null;

    /**
     * Product lines in this custom SKU bundle.
     *
     * @var Collection<int, CustomSkuProduct>
     */
    #[ORM\OneToMany(targetEntity: CustomSkuProduct::class, mappedBy: "customSku")]
    private Collection $customSkuProducts;

    /**
     * Initialise the entity with the creation timestamp set to now and empty product lines.
     */
    public function __construct()
    {
        $this->created = new DateTime();
        $this->customSkuProducts = new ArrayCollection();
    }

    /**
     * Get the custom SKU ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the category.
     */
    public function getCategory(): ?Category
    {
        return $this->category;
    }

    /**
     * Set the category.
     */
    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Get the SKU.
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * Set the SKU.
     */
    public function setSku(string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    /**
     * Get the shipping service.
     */
    public function getShippingService(): ?string
    {
        return $this->shippingService;
    }

    /**
     * Set the shipping service.
     */
    public function setShippingService(?string $shippingService): self
    {
        $this->shippingService = $shippingService;

        return $this;
    }

    /**
     * Get the postage cost.
     */
    public function getPostageCost(): string
    {
        return $this->postageCost;
    }

    /**
     * Set the postage cost.
     */
    public function setPostageCost(string $postageCost): self
    {
        $this->postageCost = $postageCost;

        return $this;
    }

    /**
     * Get the packaging cost.
     */
    public function getPackageCost(): string
    {
        return $this->packageCost;
    }

    /**
     * Set the packaging cost.
     */
    public function setPackageCost(string $packageCost): self
    {
        $this->packageCost = $packageCost;

        return $this;
    }

    /**
     * Get the status.
     */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /**
     * Set the status.
     */
    public function setStatus(?int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get the creation timestamp.
     */
    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    /**
     * Set the creation timestamp.
     */
    public function setCreated(DateTimeInterface $created): self
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Get the last update timestamp.
     */
    public function getUpdated(): ?DateTimeInterface
    {
        return $this->updated;
    }

    /**
     * Set the last update timestamp.
     */
    public function setUpdated(?DateTimeInterface $updated): self
    {
        $this->updated = $updated;

        return $this;
    }

    /**
     * Get the product lines.
     *
     * @return Collection<int, CustomSkuProduct>
     */
    public function getCustomSkuProducts(): Collection
    {
        return $this->customSkuProducts;
    }
}
