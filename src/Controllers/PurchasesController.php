<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\CategoryPaths;
use App\Entities\Product;
use App\Entities\Purchase;
use App\Entities\Supplier;
use App\Framework\Clock\ClockInterface;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Stock purchases from suppliers: list with filters and totals, create, edit,
 * mark as delivered and delete.
 *
 * Delivered purchases (and their received dates) drive the FIFO item cost of
 * imported eBay orders, so changes here affect future cost calculations.
 */
class PurchasesController extends ControllerAbstract
{
    /**
     * Purchases per page.
     */
    public const PER_PAGE = 50;

    /**
     * Amounts accepted for the total price: up to 8 digits and 2 decimals.
     */
    private const MONEY_PATTERN = '/^\d{1,8}(\.\d{1,2})?$/';

    /**
     * Status labels keyed by stored value.
     */
    public const STATUSES = [
        Purchase::STATUS_PENDING => 'Pending',
        Purchase::STATUS_DELIVERED => 'Delivered',
    ];

    /**
     * Success messages for `?done=` codes set by redirects.
     */
    private const NOTICES = [
        'created' => 'Purchase added.',
        'updated' => 'Purchase updated.',
        'received' => 'Purchase marked as delivered.',
        'deleted' => 'Purchase deleted.',
    ];

    /**
     * Create the controller.
     */
    public function __construct(
        RendererInterface $renderer,
        StreamFactoryInterface $stream,
        ResponseFactoryInterface $factory,
        EntityManagerInterface $em,
        private readonly CategoryPaths $categoryPaths,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * List purchases, newest first, filtered by status, supplier and product
     * (SKU or title), with totals for the filtered set.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filters = [
            'status' => is_string($query['status'] ?? null) && ctype_digit($query['status'])
                && isset(self::STATUSES[(int) $query['status']]) ? (int) $query['status'] : null,
            'supplier' => is_string($query['supplier'] ?? null) && ctype_digit($query['supplier'])
                ? $this->em->getRepository(Supplier::class)->find((int) $query['supplier']) : null,
            'q' => is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 100) : '',
        ];

        $criteria = array_filter(
            ['status' => $filters['status'], 'supplier' => $filters['supplier']],
            static fn (mixed $value): bool => $value !== null
        );
        $productIds = $filters['q'] !== '' ? $this->matchingProductIds($filters['q']) : null;

        if ($productIds !== null) {
            $criteria['product'] = $productIds;
        }

        $noMatches = $productIds === [];
        $total = $noMatches ? 0 : $this->purchases()->count($criteria);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1));

        return $this->render('purchases/index', [
            'purchases' => $noMatches ? [] : $this->purchases()->findBy(
                $criteria,
                ['purchasedDate' => 'DESC', 'id' => 'DESC'],
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE
            ),
            'summary' => $this->summary($criteria, $noMatches),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
            'suppliers' => $this->supplierOptions(),
            'statuses' => self::STATUSES,
            'notice' => is_string($query['done'] ?? null) ? self::NOTICES[$query['done']] ?? null : null,
        ]);
    }

    /**
     * Show the form for a new purchase, dated today and pending.
     */
    public function create(): ResponseInterface
    {
        return $this->renderForm('purchases/create', null, [
            'supplier' => '',
            'product' => '',
            'purchased_date' => $this->clock->now()->format('Y-m-d'),
            'quantity' => '1',
            'total_price' => '',
            'status' => (string) Purchase::STATUS_PENDING,
            'received_date' => '',
        ]);
    }

    /**
     * Validate and save a new purchase.
     */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $values = $this->input($request);
        [$errors, $resolved] = $this->validate($values);

        if ($errors !== [] || $resolved === null) {
            return $this->renderForm('purchases/create', null, $values, $errors);
        }

        $purchase = new Purchase();
        $this->fill($purchase, $resolved);
        $this->em->persist($purchase);
        $this->em->flush();

        return $this->redirect('/purchases?done=created');
    }

    /**
     * Show the form for editing a purchase.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function edit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $purchase = $this->findPurchase($args);

        if ($purchase === null) {
            return $this->notFound('Purchase not found.');
        }

        return $this->renderForm('purchases/edit', $purchase, [
            'supplier' => (string) ($purchase->getSupplier()?->getId() ?? ''),
            'product' => (string) ($purchase->getProduct()?->getId() ?? ''),
            'purchased_date' => $purchase->getPurchasedDate()->format('Y-m-d'),
            'quantity' => (string) $purchase->getQuantity(),
            'total_price' => $purchase->getTotalPrice(),
            'status' => (string) $purchase->getStatus(),
            'received_date' => $purchase->getReceivedDate()?->format('Y-m-d') ?? '',
        ]);
    }

    /**
     * Validate and save changes to a purchase.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $purchase = $this->findPurchase($args);

        if ($purchase === null) {
            return $this->notFound('Purchase not found.');
        }

        $values = $this->input($request);
        [$errors, $resolved] = $this->validate($values);

        if ($errors !== [] || $resolved === null) {
            return $this->renderForm('purchases/edit', $purchase, $values, $errors);
        }

        $this->fill($purchase, $resolved);
        $purchase->setUpdated(new DateTime());
        $this->em->flush();

        return $this->redirect('/purchases?done=updated');
    }

    /**
     * Mark a pending purchase as delivered today.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function receive(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $purchase = $this->findPurchase($args);

        if ($purchase === null) {
            return $this->notFound('Purchase not found.');
        }

        if ($purchase->getStatus() !== Purchase::STATUS_DELIVERED) {
            $purchase
                ->setStatus(Purchase::STATUS_DELIVERED)
                ->setReceivedDate($this->midday($this->clock->now()->format('Y-m-d')))
                ->setUpdated(new DateTime());
            $this->em->flush();
        }

        return $this->redirect('/purchases?done=received');
    }

    /**
     * Delete a purchase.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $purchase = $this->findPurchase($args);

        if ($purchase === null) {
            return $this->notFound('Purchase not found.');
        }

        $this->em->remove($purchase);
        $this->em->flush();

        return $this->redirect('/purchases?done=deleted');
    }

    /**
     * Render the create or edit form; 422 when there are validation errors.
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderForm(
        string $template,
        ?Purchase $purchase,
        array $values,
        array $errors = []
    ): ResponseInterface {
        return $this->render($template, [
            'purchase' => $purchase,
            'values' => $values,
            'errors' => $errors,
            'suppliers' => $this->supplierOptions(),
            'productGroups' => $this->productGroups(),
            'statuses' => self::STATUSES,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Count, units, spend and pending purchases for the filtered set.
     *
     * @param array<string, mixed> $criteria Repository criteria (status, supplier, product IDs).
     *
     * @return array{count: int, units: int, spend: string, pending: int}
     */
    private function summary(array $criteria, bool $noMatches): array
    {
        if ($noMatches) {
            return ['count' => 0, 'units' => 0, 'spend' => '0.00', 'pending' => 0];
        }

        $where = [];
        $params = [];
        $types = [];

        if (isset($criteria['status'])) {
            $where[] = 'status = ?';
            $params[] = $criteria['status'];
            $types[] = 'integer';
        }

        if (isset($criteria['supplier'])) {
            $where[] = 'supplier = ?';
            $params[] = $criteria['supplier']->getId();
            $types[] = 'integer';
        }

        if (isset($criteria['product'])) {
            $where[] = 'product IN (?)';
            $params[] = $criteria['product'];
            $types[] = ArrayParameterType::INTEGER;
        }

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS purchases, COALESCE(SUM(quantity), 0) AS units,'
            . ' COALESCE(SUM(total_price), 0) AS spend, COALESCE(SUM(status = 0), 0) AS pending FROM purchases'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : ''),
            $params,
            $types
        ) ?: [];

        return [
            'count' => (int) ($row['purchases'] ?? 0),
            'units' => (int) ($row['units'] ?? 0),
            'spend' => number_format((float) ($row['spend'] ?? 0), 2, '.', ''),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }

    /**
     * IDs of products whose SKU or title contains the text (case-insensitive).
     *
     * @return list<int>
     */
    private function matchingProductIds(string $text): array
    {
        $ids = [];

        foreach ($this->em->getRepository(Product::class)->findAll() as $product) {
            if (stripos($product->getSku(), $text) !== false || stripos($product->getTitle(), $text) !== false) {
                $ids[] = $product->getId();
            }
        }

        return $ids;
    }

    /**
     * Read the form fields as trimmed strings.
     *
     * @return array<string, string>
     */
    private function input(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $values = [];

        $fields = ['supplier', 'product', 'purchased_date', 'quantity', 'total_price', 'status', 'received_date'];

        foreach ($fields as $field) {
            $value = is_array($body) ? ($body[$field] ?? '') : '';
            $values[$field] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $values;
    }

    /**
     * Validate form values. On success also returns them resolved to entities and types.
     *
     * @param array<string, string> $values
     *
     * @return array{array<string, string>, array{supplier: Supplier, product: Product, purchased: DateTime,
     *     quantity: int, total: string, status: int, received: DateTime|null}|null}
     */
    private function validate(array $values): array
    {
        $errors = [];
        $supplier = ctype_digit($values['supplier'])
            ? $this->em->getRepository(Supplier::class)->find((int) $values['supplier']) : null;
        $product = ctype_digit($values['product'])
            ? $this->em->getRepository(Product::class)->find((int) $values['product']) : null;
        $purchased = $this->date($values['purchased_date']);
        $received = $values['received_date'] !== '' ? $this->date($values['received_date']) : null;
        $quantity = filter_var($values['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = ctype_digit($values['status']) && isset(self::STATUSES[(int) $values['status']])
            ? (int) $values['status'] : null;

        if ($supplier === null) {
            $errors['supplier'] = 'Choose a supplier.';
        }

        if ($product === null) {
            $errors['product'] = 'Choose a product.';
        }

        if ($purchased === null) {
            $errors['purchased_date'] = 'Enter the purchase date.';
        } elseif ($purchased > $this->midday($this->clock->now()->format('Y-m-d'))) {
            $errors['purchased_date'] = 'The purchase date cannot be in the future.';
        }

        if ($quantity === false) {
            $errors['quantity'] = 'Quantity must be a whole number of 1 or more.';
        }

        if (!preg_match(self::MONEY_PATTERN, $values['total_price'])) {
            $errors['total_price'] = 'Total price must be an amount like 29.45.';
        }

        if ($status === null) {
            $errors['status'] = 'Choose a status.';
        }

        if ($values['received_date'] !== '' && $received === null) {
            $errors['received_date'] = 'Enter a valid received date.';
        } elseif ($status === Purchase::STATUS_DELIVERED && $received === null) {
            $errors['received_date'] = 'Enter the date the delivery arrived.';
        } elseif ($status === Purchase::STATUS_PENDING && $received !== null) {
            $errors['received_date'] = 'A pending purchase has no received date; set the status to Delivered.';
        } elseif ($received !== null && $purchased !== null && $received < $purchased) {
            $errors['received_date'] = 'The received date cannot be before the purchase date.';
        }

        if ($errors !== []) {
            return [$errors, null];
        }

        return [[], [
            'supplier' => $supplier,
            'product' => $product,
            'purchased' => $purchased,
            'quantity' => $quantity,
            'total' => number_format((float) $values['total_price'], 2, '.', ''),
            'status' => $status,
            'received' => $received,
        ]];
    }

    /**
     * Copy validated values onto the purchase.
     *
     * @param array{supplier: Supplier, product: Product, purchased: DateTime, quantity: int, total: string,
     *     status: int, received: DateTime|null} $resolved
     */
    private function fill(Purchase $purchase, array $resolved): void
    {
        $purchase
            ->setSupplier($resolved['supplier'])
            ->setProduct($resolved['product'])
            ->setPurchasedDate($this->keepTime($purchase, $resolved['purchased']))
            ->setQuantity($resolved['quantity'])
            ->setTotalPrice($resolved['total'])
            ->setStatus($resolved['status'])
            ->setReceivedDate($resolved['received']);
    }

    /**
     * Keep an existing purchase's time of day when only its date is unchanged,
     * so editing other fields does not rewrite imported timestamps.
     */
    private function keepTime(Purchase $purchase, DateTime $date): DateTime
    {
        $existing = $this->em->contains($purchase) ? $purchase->getPurchasedDate() : null;

        return $existing instanceof DateTimeInterface && $existing->format('Y-m-d') === $date->format('Y-m-d')
            ? DateTime::createFromInterface($existing)
            : $date;
    }

    /**
     * Parse a Y-m-d date as midday (the time most stored purchases use), or null if invalid.
     */
    private function date(string $value): ?DateTime
    {
        $date = DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $this->midday($value) : null;
    }

    /**
     * Midday on the given Y-m-d date.
     */
    private function midday(string $date): DateTime
    {
        return new DateTime($date . ' 12:00:00');
    }

    /**
     * Suppliers for selects, keyed by ID.
     *
     * @return array<int, string>
     */
    private function supplierOptions(): array
    {
        $options = [];

        foreach ($this->em->getRepository(Supplier::class)->findBy([], ['supplierName' => 'ASC']) as $supplier) {
            $options[$supplier->getId()] = $supplier->getSupplierName();
        }

        return $options;
    }

    /**
     * Products grouped by category path for the product select (legacy grouped them the same way).
     *
     * @return array<string, array<int, string>> Group label => [product ID => "Title (SKU)"].
     */
    private function productGroups(): array
    {
        $paths = $this->categoryPaths->labels();
        $groups = [];

        foreach ($this->em->getRepository(Product::class)->findBy([], ['title' => 'ASC']) as $product) {
            $categoryId = $product->getCategory()?->getId();
            $group = $categoryId !== null ? ($paths[$categoryId] ?? 'Uncategorised') : 'Uncategorised';
            $groups[$group][$product->getId()] = $product->getTitle() . ' (' . $product->getSku() . ')';
        }

        uksort($groups, static fn (string $a, string $b): int => $a === 'Uncategorised' ? 1
            : ($b === 'Uncategorised' ? -1 : strnatcasecmp($a, $b)));

        return $groups;
    }

    /**
     * Find the purchase named by the `id` route placeholder.
     *
     * @param array<string, string> $args
     */
    private function findPurchase(array $args): ?Purchase
    {
        $id = $args['id'] ?? '';

        return ctype_digit($id) ? $this->purchases()->find((int) $id) : null;
    }

    /**
     * Purchase repository.
     *
     * @return EntityRepository<Purchase>
     */
    private function purchases(): EntityRepository
    {
        return $this->em->getRepository(Purchase::class);
    }
}
