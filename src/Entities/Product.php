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
 * Product entity mapped to the `products` table.
 *
 * Decimal columns are exposed as strings, as Doctrine maps DECIMAL to string
 * to avoid floating point precision loss.
 */
#[ORM\Entity]
#[ORM\Table(name: "products")]
#[ORM\UniqueConstraint(name: "sku_unique", columns: ["sku"])]
#[ORM\Index(name: "IDX_B3BA5A5A64C19C1", columns: ["category"])]
class Product
{
    /**
     * Status of a product that is on sale.
     */
    public const STATUS_ACTIVE = 1;

    /**
     * Status of a product that is no longer sold.
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
     * Category this product belongs to (`category` column, references `categories.id`).
     */
    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: "products")]
    #[ORM\JoinColumn(name: "category", referencedColumnName: "id")]
    private ?Category $category = null;

    /**
     * Stock keeping unit, unique across products.
     */
    #[ORM\Column(type: Types::STRING, length: 191, unique: true)]
    private string $sku;

    /**
     * Barcode / UPC.
     */
    #[ORM\Column(name: "barcode_upc", type: Types::STRING, length: 191)]
    private string $barcodeUpc;

    /**
     * Internal product code.
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $code;

    /**
     * Product title.
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $title;

    /**
     * Stock level at or below which the product is considered low on stock.
     */
    #[ORM\Column(name: "low_stock", type: Types::INTEGER, nullable: true, options: ["default" => 10])]
    private ?int $lowStock = 10;

    /**
     * Selling price.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $price;

    /**
     * Platform / selling fees.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $fees;

    /**
     * Shipping service name.
     */
    #[ORM\Column(name: "shipping_service", type: Types::STRING, length: 191, nullable: true)]
    private ?string $shippingService = null;

    /**
     * Packaging cost.
     */
    #[ORM\Column(name: "package_cost", type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $packageCost;

    /**
     * Postage cost.
     */
    #[ORM\Column(name: "postage_cost", type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $postageCost;

    /**
     * Promotion percentage.
     */
    #[ORM\Column(
        name: "promo_percentage",
        type: Types::DECIMAL,
        precision: 50,
        scale: 2,
        options: ["default" => "7.50"]
    )]
    private string $promoPercentage = '7.50';

    /**
     * Multi-buy tier 2 discount.
     */
    #[ORM\Column(name: "multi_buy_2", type: Types::INTEGER, nullable: true, options: ["default" => 2])]
    private ?int $multiBuy2 = 2;

    /**
     * Multi-buy tier 3 discount.
     */
    #[ORM\Column(name: "multi_buy_3", type: Types::INTEGER, nullable: true, options: ["default" => 5])]
    private ?int $multiBuy3 = 5;

    /**
     * Multi-buy tier 4 discount.
     */
    #[ORM\Column(name: "multi_buy_4", type: Types::INTEGER, nullable: true, options: ["default" => 10])]
    private ?int $multiBuy4 = 10;

    /**
     * Product image path or URL.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $image = null;

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
     * Status: 1 = active, 2 = inactive (see the STATUS_* constants). Some imported rows hold 0.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ["default" => 1])]
    private ?int $status = self::STATUS_ACTIVE;

    /**
     * Stock purchases of this product.
     *
     * @var Collection<int, Purchase>
     */
    #[ORM\OneToMany(targetEntity: Purchase::class, mappedBy: "product")]
    private Collection $purchases;

    /**
     * Order lines that sold this product.
     *
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: "product")]
    private Collection $orderItems;

    /**
     * Initialise the entity with the creation timestamp set to now and empty purchases and order items.
     */
    public function __construct()
    {
        $this->created = new DateTime();
        $this->purchases = new ArrayCollection();
        $this->orderItems = new ArrayCollection();
    }

    /**
     * Get the product ID.
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
     * Get the barcode / UPC.
     */
    public function getBarcodeUpc(): string
    {
        return $this->barcodeUpc;
    }

    /**
     * Set the barcode / UPC.
     */
    public function setBarcodeUpc(string $barcodeUpc): self
    {
        $this->barcodeUpc = $barcodeUpc;

        return $this;
    }

    /**
     * Get the internal product code.
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Set the internal product code.
     */
    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    /**
     * Get the product title.
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Set the product title.
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Get the low stock threshold.
     */
    public function getLowStock(): ?int
    {
        return $this->lowStock;
    }

    /**
     * Set the low stock threshold.
     */
    public function setLowStock(?int $lowStock): self
    {
        $this->lowStock = $lowStock;

        return $this;
    }

    /**
     * Get the selling price.
     */
    public function getPrice(): string
    {
        return $this->price;
    }

    /**
     * Set the selling price.
     */
    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    /**
     * Get the selling fees.
     */
    public function getFees(): string
    {
        return $this->fees;
    }

    /**
     * Set the selling fees.
     */
    public function setFees(string $fees): self
    {
        $this->fees = $fees;

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
     * Get the promotion percentage.
     */
    public function getPromoPercentage(): string
    {
        return $this->promoPercentage;
    }

    /**
     * Set the promotion percentage.
     */
    public function setPromoPercentage(string $promoPercentage): self
    {
        $this->promoPercentage = $promoPercentage;

        return $this;
    }

    /**
     * Get the multi-buy tier 2 value.
     */
    public function getMultiBuy2(): ?int
    {
        return $this->multiBuy2;
    }

    /**
     * Set the multi-buy tier 2 value.
     */
    public function setMultiBuy2(?int $multiBuy2): self
    {
        $this->multiBuy2 = $multiBuy2;

        return $this;
    }

    /**
     * Get the multi-buy tier 3 value.
     */
    public function getMultiBuy3(): ?int
    {
        return $this->multiBuy3;
    }

    /**
     * Set the multi-buy tier 3 value.
     */
    public function setMultiBuy3(?int $multiBuy3): self
    {
        $this->multiBuy3 = $multiBuy3;

        return $this;
    }

    /**
     * Get the multi-buy tier 4 value.
     */
    public function getMultiBuy4(): ?int
    {
        return $this->multiBuy4;
    }

    /**
     * Set the multi-buy tier 4 value.
     */
    public function setMultiBuy4(?int $multiBuy4): self
    {
        $this->multiBuy4 = $multiBuy4;

        return $this;
    }

    /**
     * Get the product image.
     */
    public function getImage(): ?string
    {
        return $this->image;
    }

    /**
     * Set the product image.
     */
    public function setImage(?string $image): self
    {
        $this->image = $image;

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
     * Get the product status.
     */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /**
     * Set the product status.
     */
    public function setStatus(?int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get the stock purchases of this product.
     *
     * @return Collection<int, Purchase>
     */
    public function getPurchases(): Collection
    {
        return $this->purchases;
    }

    /**
     * Get the order lines that sold this product.
     *
     * @return Collection<int, OrderItem>
     */
    public function getOrderItems(): Collection
    {
        return $this->orderItems;
    }
}
