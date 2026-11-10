<?php

declare(strict_types=1);

namespace App\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Purchase entity mapped to the `purchases` table.
 *
 * Stock bought from a supplier for a product.
 *
 * Decimal columns are exposed as strings, as Doctrine maps DECIMAL to string
 * to avoid floating point precision loss.
 */
#[ORM\Entity]
#[ORM\Table(name: "purchases")]
#[ORM\Index(name: "IDX_AA6431FE9B2A6C7E", columns: ["supplier"])]
#[ORM\Index(name: "IDX_AA6431FED34A04AD", columns: ["product"])]
class Purchase
{
    /**
     * Status of a purchase that has not been delivered yet.
     */
    public const STATUS_PENDING = 0;

    /**
     * Status of a purchase that has been delivered.
     */
    public const STATUS_DELIVERED = 1;

    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Supplier the stock was bought from (`supplier` column, references `suppliers.id`).
     */
    #[ORM\ManyToOne(targetEntity: Supplier::class, inversedBy: "purchases")]
    #[ORM\JoinColumn(name: "supplier", referencedColumnName: "id")]
    private ?Supplier $supplier = null;

    /**
     * Product purchased (`product` column, references `products.id`).
     */
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: "purchases")]
    #[ORM\JoinColumn(name: "product", referencedColumnName: "id")]
    private ?Product $product = null;

    /**
     * When the stock was purchased.
     */
    #[ORM\Column(name: "purchased_date", type: Types::DATETIME_MUTABLE, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $purchasedDate;

    /**
     * Quantity purchased.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    /**
     * Total price paid for the purchase.
     */
    #[ORM\Column(name: "total_price", type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $totalPrice;

    /**
     * Delivery status: 0 = pending (not delivered), 1 = delivered (see the STATUS_* constants).
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $status;

    /**
     * When the stock was received.
     */
    #[ORM\Column(name: "received_date", type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $receivedDate = null;

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
     * Initialise the entity with the purchase date and creation timestamp set to now.
     */
    public function __construct()
    {
        $this->purchasedDate = new DateTime();
        $this->created = new DateTime();
    }

    /**
     * Get the purchase ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the supplier.
     */
    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    /**
     * Set the supplier.
     */
    public function setSupplier(?Supplier $supplier): self
    {
        $this->supplier = $supplier;

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
     * Get the purchase date.
     */
    public function getPurchasedDate(): DateTimeInterface
    {
        return $this->purchasedDate;
    }

    /**
     * Set the purchase date.
     */
    public function setPurchasedDate(DateTimeInterface $purchasedDate): self
    {
        $this->purchasedDate = $purchasedDate;

        return $this;
    }

    /**
     * Get the quantity.
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * Set the quantity.
     */
    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Get the total price.
     */
    public function getTotalPrice(): string
    {
        return $this->totalPrice;
    }

    /**
     * Set the total price.
     */
    public function setTotalPrice(string $totalPrice): self
    {
        $this->totalPrice = $totalPrice;

        return $this;
    }

    /**
     * Get the status.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Set the status.
     */
    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get the received date.
     */
    public function getReceivedDate(): ?DateTimeInterface
    {
        return $this->receivedDate;
    }

    /**
     * Set the received date.
     */
    public function setReceivedDate(?DateTimeInterface $receivedDate): self
    {
        $this->receivedDate = $receivedDate;

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
