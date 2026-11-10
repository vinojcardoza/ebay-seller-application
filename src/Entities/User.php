<?php

declare(strict_types=1);

namespace App\Entities;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * User entity mapped to the `users` table.
 *
 * An account that can log in to the app.
 */
#[ORM\Entity]
#[ORM\Table(name: "users")]
#[ORM\UniqueConstraint(name: "unq_email", columns: ["email"])]
class User
{
    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Role code (defaults to 50). The legacy app stores but never checks it, so no values are defined.
     */
    #[ORM\Column(type: Types::INTEGER, options: ["default" => 50])]
    private int $role = 50;

    /**
     * Per-user hash value. The legacy app stores but never uses it.
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $hash;

    /**
     * Email address, unique across users.
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $email;

    /**
     * Login name.
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $login;

    /**
     * Bcrypt password hash (from password_hash()); check with password_verify(), never store plain text.
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $password;

    /**
     * User's full name.
     */
    #[ORM\Column(name: "full_name", type: Types::STRING, length: 191, nullable: true)]
    private ?string $fullName = null;

    /**
     * When the user last logged in.
     */
    #[ORM\Column(name: "last_login", type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $lastLogin = null;

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
     * Get the user ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the role.
     */
    public function getRole(): int
    {
        return $this->role;
    }

    /**
     * Set the role.
     */
    public function setRole(int $role): self
    {
        $this->role = $role;

        return $this;
    }

    /**
     * Get the hash.
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Set the hash.
     */
    public function setHash(string $hash): self
    {
        $this->hash = $hash;

        return $this;
    }

    /**
     * Get the email.
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Set the email.
     */
    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Get the login.
     */
    public function getLogin(): string
    {
        return $this->login;
    }

    /**
     * Set the login.
     */
    public function setLogin(string $login): self
    {
        $this->login = $login;

        return $this;
    }

    /**
     * Get the password hash.
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Set the password hash.
     */
    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get the full name.
     */
    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    /**
     * Set the full name.
     */
    public function setFullName(?string $fullName): self
    {
        $this->fullName = $fullName;

        return $this;
    }

    /**
     * Get the last login time.
     */
    public function getLastLogin(): ?DateTimeInterface
    {
        return $this->lastLogin;
    }

    /**
     * Set the last login time.
     */
    public function setLastLogin(?DateTimeInterface $lastLogin): self
    {
        $this->lastLogin = $lastLogin;

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
