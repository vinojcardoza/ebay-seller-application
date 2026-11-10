<?php

declare(strict_types=1);

namespace App\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Order item entity mapped to the `order_items` table.
 *
 * One product line within an order, with its cost, quantity and sale amounts.
 *
 * Decimal columns are exposed as strings, as Doctrine maps DECIMAL to string
 * to avoid floating point precision loss.
 */
#[ORM\Entity]
#[ORM\Table(name: "order_items")]
#[ORM\Index(name: "IDX_62809DB0D34A04AD", columns: ["product"])]
#[ORM\Index(name: "IDX_62809DB08D9F6D38", columns: ["order_id"])]
class OrderItem
{
    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Product sold (`product` column, references `products.id`).
     */
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: "orderItems")]
    #[ORM\JoinColumn(name: "product", referencedColumnName: "id")]
    private ?Product $product = null;

    /**
     * Order this line belongs to (`order_id` column, references `orders.id`).
     */
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: "items")]
    #[ORM\JoinColumn(name: "order_id", referencedColumnName: "id")]
    private ?Order $order = null;

    /**
     * Cost of the item to the business.
     */
    #[ORM\Column(name: "item_cost", type: Types::DECIMAL, precision: 50, scale: 2, nullable: true)]
    private ?string $itemCost = null;

    /**
     * Quantity sold.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $quantity = null;

    /**
     * Unit price the item sold for.
     */
    #[ORM\Column(name: "sold_price", type: Types::DECIMAL, precision: 50, scale: 2, nullable: true)]
    private ?string $soldPrice = null;

    /**
     * Platform fees deducted from the sale.
     */
    #[ORM\Column(name: "fees_deductions", type: Types::DECIMAL, precision: 50, scale: 2, nullable: true)]
    private ?string $feesDeductions = null;

    /**
     * Total sale amount for this line.
     */
    #[ORM\Column(name: "total_sold_price", type: Types::DECIMAL, precision: 50, scale: 2, nullable: true)]
    private ?string $totalSoldPrice = null;

    /**
     * Free-text notes about the line.
     */
    #[ORM\Column(name: "additional_notes", type: Types::TEXT, nullable: true)]
    private ?string $additionalNotes = null;

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
     * Get the order item ID.
     */
    public function getId(): int
    {
        return $this->id;
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
     * Get the order.
     */
    public function getOrder(): ?Order
    {
        return $this->order;
    }

    /**
     * Set the order.
     */
    public function setOrder(?Order $order): self
    {
        $this->order = $order;

        return $this;
    }

    /**
     * Get the item cost.
     */
    public function getItemCost(): ?string
    {
        return $this->itemCost;
    }

    /**
     * Set the item cost.
     */
    public function setItemCost(?string $itemCost): self
    {
        $this->itemCost = $itemCost;

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
     * Get the sold price.
     */
    public function getSoldPrice(): ?string
    {
        return $this->soldPrice;
    }

    /**
     * Set the sold price.
     */
    public function setSoldPrice(?string $soldPrice): self
    {
        $this->soldPrice = $soldPrice;

        return $this;
    }

    /**
     * Get the fees deductions.
     */
    public function getFeesDeductions(): ?string
    {
        return $this->feesDeductions;
    }

    /**
     * Set the fees deductions.
     */
    public function setFeesDeductions(?string $feesDeductions): self
    {
        $this->feesDeductions = $feesDeductions;

        return $this;
    }

    /**
     * Get the total sold price.
     */
    public function getTotalSoldPrice(): ?string
    {
        return $this->totalSoldPrice;
    }

    /**
     * Set the total sold price.
     */
    public function setTotalSoldPrice(?string $totalSoldPrice): self
    {
        $this->totalSoldPrice = $totalSoldPrice;

        return $this;
    }

    /**
     * Get the additional notes.
     */
    public function getAdditionalNotes(): ?string
    {
        return $this->additionalNotes;
    }

    /**
     * Set the additional notes.
     */
    public function setAdditionalNotes(?string $additionalNotes): self
    {
        $this->additionalNotes = $additionalNotes;

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
