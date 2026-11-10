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
 * Order entity mapped to the `orders` table.
 *
 * A sale (or adjustment such as a refund or return) on a selling platform,
 * with shipping details and the buyer's contact and address. Line items
 * are held in `order_items`.
 *
 * Decimal columns are exposed as strings, as Doctrine maps DECIMAL to string
 * to avoid floating point precision loss.
 */
#[ORM\Entity]
#[ORM\Table(name: "orders")]
class Order
{
    /**
     * Primary key.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private int $id;

    /**
     * Selling platform (e.g. Ebay, Etsy, Direct).
     */
    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $platform;

    /**
     * Order type (e.g. Purchase, Refund, Return, Cancelled).
     */
    #[ORM\Column(name: "order_type", type: Types::STRING, length: 191, options: ["default" => "Purchase"])]
    private string $orderType = 'Purchase';

    /**
     * Platform's order reference.
     */
    #[ORM\Column(name: "order_reference", type: Types::STRING, length: 191)]
    private string $orderReference;

    /**
     * When the order was placed.
     */
    #[ORM\Column(name: "order_date", type: Types::DATETIME_MUTABLE, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $orderDate;

    /**
     * Packaging cost.
     */
    #[ORM\Column(name: "packaging_cost", type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $packagingCost;

    /**
     * Shipping company name.
     */
    #[ORM\Column(name: "shipping_company", type: Types::STRING, length: 191, nullable: true)]
    private ?string $shippingCompany = null;

    /**
     * Shipping service name.
     */
    #[ORM\Column(name: "shipping_service", type: Types::STRING, length: 191, nullable: true)]
    private ?string $shippingService = null;

    /**
     * Shipment tracking code.
     */
    #[ORM\Column(name: "tracking_code", type: Types::STRING, length: 191, nullable: true)]
    private ?string $trackingCode = null;

    /**
     * Shipping status code (defaults to 0). Not used by the legacy app beyond data imports;
     * delivery is tracked by the shipping delivery date instead.
     */
    #[ORM\Column(name: "shipping_status", type: Types::INTEGER, nullable: true, options: ["default" => 0])]
    private ?int $shippingStatus = 0;

    /**
     * When the order was shipped.
     */
    #[ORM\Column(name: "shipping_datetime", type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $shippingDatetime = null;

    /**
     * When the order was delivered.
     */
    #[ORM\Column(name: "shipping_delivery_datetime", type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $shippingDeliveryDatetime = null;

    /**
     * Postage cost.
     */
    #[ORM\Column(name: "postage_cost", type: Types::DECIMAL, precision: 50, scale: 2)]
    private string $postageCost;

    /**
     * Buyer's eBay user ID.
     */
    #[ORM\Column(name: "ebay_user_id", type: Types::TEXT, nullable: true)]
    private ?string $ebayUserId = null;

    /**
     * Buyer's full name.
     */
    #[ORM\Column(name: "full_name", type: Types::TEXT, nullable: true)]
    private ?string $fullName = null;

    /**
     * Buyer's email address.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $email = null;

    /**
     * Buyer's phone number.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $phone = null;

    /**
     * Shipping address, first street line.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $street1 = null;

    /**
     * Shipping address, second street line.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $street2 = null;

    /**
     * Shipping address city.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $city = null;

    /**
     * Shipping address state or county.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $state = null;

    /**
     * Shipping address postcode.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $postcode = null;

    /**
     * Shipping address country code.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $country = null;

    /**
     * Shipping address country name.
     */
    #[ORM\Column(name: "country_name", type: Types::TEXT, nullable: true)]
    private ?string $countryName = null;

    /**
     * Order status code. The legacy app always sets 1 and defines no other values.
     */
    #[ORM\Column(type: Types::INTEGER, options: ["default" => 1])]
    private int $status = 1;

    /**
     * Creation timestamp.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    /**
     * Free-text notes about the order.
     */
    #[ORM\Column(name: "additional_notes", type: Types::TEXT, nullable: true)]
    private ?string $additionalNotes = null;

    /**
     * Last update timestamp.
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?DateTimeInterface $updated = null;

    /**
     * Product lines in this order.
     *
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: "order")]
    private Collection $items;

    /**
     * Money movements linked to this order.
     *
     * @var Collection<int, Transaction>
     */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: "order")]
    private Collection $transactions;

    /**
     * Initialise the entity with the order date and creation timestamp set to now,
     * empty order items and transactions.
     */
    public function __construct()
    {
        $this->orderDate = new DateTime();
        $this->created = new DateTime();
        $this->items = new ArrayCollection();
        $this->transactions = new ArrayCollection();
    }

    /**
     * Get the order ID.
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get the platform.
     */
    public function getPlatform(): string
    {
        return $this->platform;
    }

    /**
     * Set the platform.
     */
    public function setPlatform(string $platform): self
    {
        $this->platform = $platform;

        return $this;
    }

    /**
     * Get the order type.
     */
    public function getOrderType(): string
    {
        return $this->orderType;
    }

    /**
     * Set the order type.
     */
    public function setOrderType(string $orderType): self
    {
        $this->orderType = $orderType;

        return $this;
    }

    /**
     * Get the order reference.
     */
    public function getOrderReference(): string
    {
        return $this->orderReference;
    }

    /**
     * Set the order reference.
     */
    public function setOrderReference(string $orderReference): self
    {
        $this->orderReference = $orderReference;

        return $this;
    }

    /**
     * Get the order date.
     */
    public function getOrderDate(): DateTimeInterface
    {
        return $this->orderDate;
    }

    /**
     * Set the order date.
     */
    public function setOrderDate(DateTimeInterface $orderDate): self
    {
        $this->orderDate = $orderDate;

        return $this;
    }

    /**
     * Get the packaging cost.
     */
    public function getPackagingCost(): string
    {
        return $this->packagingCost;
    }

    /**
     * Set the packaging cost.
     */
    public function setPackagingCost(string $packagingCost): self
    {
        $this->packagingCost = $packagingCost;

        return $this;
    }

    /**
     * Get the shipping company.
     */
    public function getShippingCompany(): ?string
    {
        return $this->shippingCompany;
    }

    /**
     * Set the shipping company.
     */
    public function setShippingCompany(?string $shippingCompany): self
    {
        $this->shippingCompany = $shippingCompany;

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
     * Get the tracking code.
     */
    public function getTrackingCode(): ?string
    {
        return $this->trackingCode;
    }

    /**
     * Set the tracking code.
     */
    public function setTrackingCode(?string $trackingCode): self
    {
        $this->trackingCode = $trackingCode;

        return $this;
    }

    /**
     * Get the shipping status.
     */
    public function getShippingStatus(): ?int
    {
        return $this->shippingStatus;
    }

    /**
     * Set the shipping status.
     */
    public function setShippingStatus(?int $shippingStatus): self
    {
        $this->shippingStatus = $shippingStatus;

        return $this;
    }

    /**
     * Get the shipping date.
     */
    public function getShippingDatetime(): ?DateTimeInterface
    {
        return $this->shippingDatetime;
    }

    /**
     * Set the shipping date.
     */
    public function setShippingDatetime(?DateTimeInterface $shippingDatetime): self
    {
        $this->shippingDatetime = $shippingDatetime;

        return $this;
    }

    /**
     * Get the delivery date.
     */
    public function getShippingDeliveryDatetime(): ?DateTimeInterface
    {
        return $this->shippingDeliveryDatetime;
    }

    /**
     * Set the delivery date.
     */
    public function setShippingDeliveryDatetime(?DateTimeInterface $shippingDeliveryDatetime): self
    {
        $this->shippingDeliveryDatetime = $shippingDeliveryDatetime;

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
     * Get the email.
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Set the email.
     */
    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Get the phone.
     */
    public function getPhone(): ?string
    {
        return $this->phone;
    }

    /**
     * Set the phone.
     */
    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * Get the street line 1.
     */
    public function getStreet1(): ?string
    {
        return $this->street1;
    }

    /**
     * Set the street line 1.
     */
    public function setStreet1(?string $street1): self
    {
        $this->street1 = $street1;

        return $this;
    }

    /**
     * Get the street line 2.
     */
    public function getStreet2(): ?string
    {
        return $this->street2;
    }

    /**
     * Set the street line 2.
     */
    public function setStreet2(?string $street2): self
    {
        $this->street2 = $street2;

        return $this;
    }

    /**
     * Get the city.
     */
    public function getCity(): ?string
    {
        return $this->city;
    }

    /**
     * Set the city.
     */
    public function setCity(?string $city): self
    {
        $this->city = $city;

        return $this;
    }

    /**
     * Get the state.
     */
    public function getState(): ?string
    {
        return $this->state;
    }

    /**
     * Set the state.
     */
    public function setState(?string $state): self
    {
        $this->state = $state;

        return $this;
    }

    /**
     * Get the postcode.
     */
    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    /**
     * Set the postcode.
     */
    public function setPostcode(?string $postcode): self
    {
        $this->postcode = $postcode;

        return $this;
    }

    /**
     * Get the country code.
     */
    public function getCountry(): ?string
    {
        return $this->country;
    }

    /**
     * Set the country code.
     */
    public function setCountry(?string $country): self
    {
        $this->country = $country;

        return $this;
    }

    /**
     * Get the country name.
     */
    public function getCountryName(): ?string
    {
        return $this->countryName;
    }

    /**
     * Set the country name.
     */
    public function setCountryName(?string $countryName): self
    {
        $this->countryName = $countryName;

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
     * Get the order items.
     *
     * @return Collection<int, OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /**
     * Get the transactions.
     *
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }
}
