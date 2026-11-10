<?php

declare(strict_types=1);

namespace App\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Transaction entity mapped to the `transactions` table.
 *
 * A money movement (income or expense), optionally linked to an order.
 *
 * Decimal columns are exposed as strings, as Doctrine maps DECIMAL to string
 * to avoid floating point precision loss.
 */
#[ORM\Entity]
#[ORM\Table(name: "transactions")]
#[ORM\Index(name: "IDX_EAA81A4C8D9F6D38", columns: ["order_id"])]
class Transaction
{
    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Order this transaction relates to, if any (`order_id` column, references `orders.id`).
     */
    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: "transactions")]
    #[ORM\JoinColumn(name: "order_id", referencedColumnName: "id")]
    private ?Order $order = null;

    /**
     * When the transaction happened.
     */
    #[ORM\Column(
        name: "transaction_datetime",
        type: Types::DATETIME_MUTABLE,
        options: ["default" => "CURRENT_TIMESTAMP"]
    )]
    private DateTimeInterface $transactionDatetime;

    /**
     * Short description of the transaction.
     */
    #[ORM\Column(name: "transaction_title", type: Types::STRING, length: 191, nullable: true)]
    private ?string $transactionTitle = null;

    /**
     * External reference for the transaction (e.g. payment provider ID).
     */
    #[ORM\Column(name: "transaction_id", type: Types::STRING, length: 191, nullable: true)]
    private ?string $transactionId = null;

    /**
     * Amount paid out.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $expense;

    /**
     * Amount received.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $income;

    /**
     * Free-text notes about the transaction.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

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
     * Initialise the entity with the transaction date and creation timestamp set to now.
     */
    public function __construct()
    {
        $this->transactionDatetime = new DateTime();
        $this->created = new DateTime();
    }

    /**
     * Get the transaction ID.
     */
    public function getId(): int
    {
        return $this->id;
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
     * Get the transaction date.
     */
    public function getTransactionDatetime(): DateTimeInterface
    {
        return $this->transactionDatetime;
    }

    /**
     * Set the transaction date.
     */
    public function setTransactionDatetime(DateTimeInterface $transactionDatetime): self
    {
        $this->transactionDatetime = $transactionDatetime;

        return $this;
    }

    /**
     * Get the transaction title.
     */
    public function getTransactionTitle(): ?string
    {
        return $this->transactionTitle;
    }

    /**
     * Set the transaction title.
     */
    public function setTransactionTitle(?string $transactionTitle): self
    {
        $this->transactionTitle = $transactionTitle;

        return $this;
    }

    /**
     * Get the external transaction ID.
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    /**
     * Set the external transaction ID.
     */
    public function setTransactionId(?string $transactionId): self
    {
        $this->transactionId = $transactionId;

        return $this;
    }

    /**
     * Get the expense.
     */
    public function getExpense(): string
    {
        return $this->expense;
    }

    /**
     * Set the expense.
     */
    public function setExpense(string $expense): self
    {
        $this->expense = $expense;

        return $this;
    }

    /**
     * Get the income.
     */
    public function getIncome(): string
    {
        return $this->income;
    }

    /**
     * Set the income.
     */
    public function setIncome(string $income): self
    {
        $this->income = $income;

        return $this;
    }

    /**
     * Get the notes.
     */
    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * Set the notes.
     */
    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;

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
