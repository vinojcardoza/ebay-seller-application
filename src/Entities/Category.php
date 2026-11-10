<?php

declare(strict_types=1);

namespace App\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Category entity mapped to the `categories` table.
 *
 * Categories form a hierarchy through `parent_id`, where 0 marks a top-level
 * category.
 */
#[ORM\Entity]
#[ORM\Table(name: "categories")]
class Category
{
    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Category title.
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $title;

    /**
     * Parent category ID (0 = top-level category).
     */
    #[ORM\Column(name: "parent_id", type: Types::INTEGER, nullable: true, options: ["default" => 0])]
    private ?int $parentId = 0;

    /**
     * Sort position within the menu.
     */
    #[ORM\Column(name: "menu_order", type: Types::INTEGER, nullable: true, options: ["default" => 0])]
    private ?int $menuOrder = 0;

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

    public function __construct()
    {
        $this->created = new DateTime();
    }

    /**
     * Get the category ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the category title.
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Set the category title.
     */
    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Get the parent category ID.
     */
    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    /**
     * Set the parent category ID.
     */
    public function setParentId(?int $parentId): self
    {
        $this->parentId = $parentId;

        return $this;
    }

    /**
     * Get the menu sort position.
     */
    public function getMenuOrder(): ?int
    {
        return $this->menuOrder;
    }

    /**
     * Set the menu sort position.
     */
    public function setMenuOrder(?int $menuOrder): self
    {
        $this->menuOrder = $menuOrder;

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
