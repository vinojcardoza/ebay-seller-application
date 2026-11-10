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
 * Supplier entity mapped to the `suppliers` table.
 *
 * A supplier that stock is purchased from.
 */
#[ORM\Entity]
#[ORM\Table(name: "suppliers")]
class Supplier
{
    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Supplier name.
     */
    #[ORM\Column(name: "supplier_name", type: Types::STRING, length: 191)]
    private string $supplierName;

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
     * Stock purchases from this supplier.
     *
     * @var Collection<int, Purchase>
     */
    #[ORM\OneToMany(targetEntity: Purchase::class, mappedBy: "supplier")]
    private Collection $purchases;

    /**
     * Initialise the entity with the creation timestamp set to now and empty purchases.
     */
    public function __construct()
    {
        $this->created = new DateTime();
        $this->purchases = new ArrayCollection();
    }

    /**
     * Get the supplier ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the supplier name.
     */
    public function getSupplierName(): string
    {
        return $this->supplierName;
    }

    /**
     * Set the supplier name.
     */
    public function setSupplierName(string $supplierName): self
    {
        $this->supplierName = $supplierName;

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
     * Get the purchases.
     *
     * @return Collection<int, Purchase>
     */
    public function getPurchases(): Collection
    {
        return $this->purchases;
    }
}
