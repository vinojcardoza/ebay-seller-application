<?php

declare(strict_types=1);

namespace App\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Custom SKU product entity mapped to the `custom_sku_products` table.
 *
 * One product line within a custom SKU bundle, with its price, fees and quantity.
 *
 * Decimal columns are exposed as strings, as Doctrine maps DECIMAL to string
 * to avoid floating point precision loss.
 */
#[ORM\Entity]
#[ORM\Table(name: "custom_sku_products")]
#[ORM\Index(name: "IDX_4B60D644A229C385", columns: ["custom_sku"])]
#[ORM\Index(name: "IDX_4B60D644D34A04AD", columns: ["product"])]
class CustomSkuProduct
{
    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Custom SKU this line belongs to (`custom_sku` column, references `custom_skus.id`).
     */
    #[ORM\ManyToOne(targetEntity: CustomSku::class, inversedBy: "customSkuProducts")]
    #[ORM\JoinColumn(name: "custom_sku", referencedColumnName: "id")]
    private ?CustomSku $customSku = null;

    /**
     * Product in the bundle (`product` column, references `products.id`).
     */
    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: "product", referencedColumnName: "id")]
    private ?Product $product = null;

    /**
     * Price of this product within the bundle.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $price;

    /**
     * Selling fees for this product within the bundle.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $fees;

    /**
     * Quantity of the product in the bundle.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ["default" => 1])]
    private ?int $quantity = 1;

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
     * Initialise the entity with the creation timestamp set to now.
     */
    public function __construct()
    {
        $this->created = new DateTime();
    }

    /**
     * Get the custom SKU product ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the custom SKU.
     */
    public function getCustomSku(): ?CustomSku
    {
        return $this->customSku;
    }

    /**
     * Set the custom SKU.
     */
    public function setCustomSku(?CustomSku $customSku): self
    {
        $this->customSku = $customSku;

        return $this;
    }

    /**
     * Get the product.
     */
    public function getProduct(): ?Product
    {
        return $this->product;
    }

    /**
     * Set the product.
     */
    public function setProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }

    /**
     * Get the price.
     */
    public function getPrice(): string
    {
        return $this->price;
    }

    /**
     * Set the price.
     */
    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    /**
     * Get the fees.
     */
    public function getFees(): string
    {
        return $this->fees;
    }

    /**
     * Set the fees.
     */
    public function setFees(string $fees): self
    {
        $this->fees = $fees;

        return $this;
    }

    /**
     * Get the quantity.
     */
    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    /**
     * Set the quantity.
     */
    public function setQuantity(?int $quantity): self
    {
        $this->quantity = $quantity;

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
}
