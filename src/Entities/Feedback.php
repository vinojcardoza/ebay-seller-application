<?php

declare(strict_types=1);

namespace App\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Feedback entity mapped to the `feedbacks` table.
 *
 * A feedback comment left by an eBay buyer for a sold item.
 *
 * Decimal columns are exposed as strings, as Doctrine maps DECIMAL to string
 * to avoid floating point precision loss.
 */
#[ORM\Entity]
#[ORM\Table(name: "feedbacks")]
class Feedback
{
    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * eBay user ID of the buyer who left the feedback.
     */
    #[ORM\Column(name: "ebay_user_id", type: Types::TEXT, nullable: true)]
    private ?string $ebayUserId = null;

    /**
     * Feedback comment text.
     */
    #[ORM\Column(name: "comment_text", type: Types::TEXT, nullable: true)]
    private ?string $commentText = null;

    /**
     * When the feedback was left.
     */
    #[ORM\Column(name: "comment_time", type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $commentTime = null;

    /**
     * Feedback type as reported by eBay (e.g. Positive).
     */
    #[ORM\Column(name: "comment_type", type: Types::STRING, length: 191, nullable: true)]
    private ?string $commentType = null;

    /**
     * eBay item (listing) ID.
     */
    #[ORM\Column(name: "item_id", type: Types::STRING, length: 191, nullable: true)]
    private ?string $itemId = null;

    /**
     * Title of the item the feedback is for.
     */
    #[ORM\Column(name: "item_title", type: Types::TEXT, nullable: true)]
    private ?string $itemTitle = null;

    /**
     * Price the item sold for.
     */
    #[ORM\Column(name: "item_price", type: Types::DECIMAL, precision: 50, scale: 2, nullable: true)]
    private ?string $itemPrice = null;

    /**
     * eBay feedback ID.
     */
    #[ORM\Column(name: "feedback_id", type: Types::TEXT, nullable: true)]
    private ?string $feedbackId = null;

    /**
     * eBay transaction ID.
     */
    #[ORM\Column(name: "transaction_id", type: Types::TEXT, nullable: true)]
    private ?string $transactionId = null;

    /**
     * eBay order line item ID.
     */
    #[ORM\Column(name: "order_line_item_id", type: Types::TEXT, nullable: true)]
    private ?string $orderLineItemId = null;

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
     * Get the feedback ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the eBay user ID.
     */
    public function getEbayUserId(): ?string
    {
        return $this->ebayUserId;
    }

    /**
     * Set the eBay user ID.
     */
    public function setEbayUserId(?string $ebayUserId): self
    {
        $this->ebayUserId = $ebayUserId;

        return $this;
    }

    /**
     * Get the comment text.
     */
    public function getCommentText(): ?string
    {
        return $this->commentText;
    }

    /**
     * Set the comment text.
     */
    public function setCommentText(?string $commentText): self
    {
        $this->commentText = $commentText;

        return $this;
    }

    /**
     * Get the comment time.
     */
    public function getCommentTime(): ?DateTimeInterface
    {
        return $this->commentTime;
    }

    /**
     * Set the comment time.
     */
    public function setCommentTime(?DateTimeInterface $commentTime): self
    {
        $this->commentTime = $commentTime;

        return $this;
    }

    /**
     * Get the comment type.
     */
    public function getCommentType(): ?string
    {
        return $this->commentType;
    }

    /**
     * Set the comment type.
     */
    public function setCommentType(?string $commentType): self
    {
        $this->commentType = $commentType;

        return $this;
    }

    /**
     * Get the item ID.
     */
    public function getItemId(): ?string
    {
        return $this->itemId;
    }

    /**
     * Set the item ID.
     */
    public function setItemId(?string $itemId): self
    {
        $this->itemId = $itemId;

        return $this;
    }

    /**
     * Get the item title.
     */
    public function getItemTitle(): ?string
    {
        return $this->itemTitle;
    }

    /**
     * Set the item title.
     */
    public function setItemTitle(?string $itemTitle): self
    {
        $this->itemTitle = $itemTitle;

        return $this;
    }

    /**
     * Get the item price.
     */
    public function getItemPrice(): ?string
    {
        return $this->itemPrice;
    }

    /**
     * Set the item price.
     */
    public function setItemPrice(?string $itemPrice): self
    {
        $this->itemPrice = $itemPrice;

        return $this;
    }

    /**
     * Get the eBay feedback ID.
     */
    public function getFeedbackId(): ?string
    {
        return $this->feedbackId;
    }

    /**
     * Set the eBay feedback ID.
     */
    public function setFeedbackId(?string $feedbackId): self
    {
        $this->feedbackId = $feedbackId;

        return $this;
    }

    /**
     * Get the eBay transaction ID.
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    /**
     * Set the eBay transaction ID.
     */
    public function setTransactionId(?string $transactionId): self
    {
        $this->transactionId = $transactionId;

        return $this;
    }

    /**
     * Get the order line item ID.
     */
    public function getOrderLineItemId(): ?string
    {
        return $this->orderLineItemId;
    }

    /**
     * Set the order line item ID.
     */
    public function setOrderLineItemId(?string $orderLineItemId): self
    {
        $this->orderLineItemId = $orderLineItemId;

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
