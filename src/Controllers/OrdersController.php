<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\CategoryPaths;
use App\Ebay\EbayException;
use App\Ebay\EbayNotConnectedException;
use App\Ebay\Import\OrderImportException;
use App\Ebay\Import\PurchaseCostCalculator;
use App\Ebay\Import\RecentOrders;
use App\Ebay\Import\TrackingSynchroniser;
use App\Entities\Order;
use App\Entities\OrderItem;
use App\Entities\Product;
use App\Entities\Transaction;
use App\Framework\Clock\ClockInterface;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Orders: searchable list with totals and profit, order details with items
 * and transactions, create/edit/delete orders, and add/edit/remove items.
 * From eBay: recent orders not imported yet (listed last, each importable)
 * and syncing shipment tracking.
 *
 * An item's total is always its sold price minus fees; both may be negative
 * (refunds, returns). Profit = net total − item cost × quantity − postage −
 * packaging, as in the legacy app.
 */
class OrdersController extends ControllerAbstract
{
    /**
     * Orders per page.
     */
    public const PER_PAGE = 50;

    /**
     * Selling platforms.
     */
    public const PLATFORMS = ['Ebay', 'Etsy', 'Direct'];

    /**
     * Order types (legacy order form).
     */
    public const TYPES = ['Purchase', 'Replacement', 'Refund', 'Return', 'Cancelled', 'Gift', 'Adjustment'];

    /**
     * Shipping services offered in the form (legacy list): code => label.
     */
    public const SHIPPING_SERVICES = [
        'CRL48' => 'Royal Mail 48',
        'TOLP48' => 'Royal Mail 48 Tracked',
        'BPL2' => 'Royal Mail 2nd Class (BPL2)',
        'BPL1' => 'Royal Mail 1st Class (BPL1)',
        'CRL24' => 'Royal Mail 24',
        'TOLP24' => 'Royal Mail 24 Tracked',
        'CLASS2EBAY' => 'Royal Mail 2nd Class (eBay)',
        'CLASS1EBAY' => 'Royal Mail 1st Class (eBay)',
        'evri' => 'Evri',
        'inpost' => 'InPost',
        'yodel' => 'Yodel Direct',
    ];

    /**
     * Tracking filter options: value => label.
     */
    public const TRACKING_FILTERS = [
        'none' => 'No tracking',
        'tracked' => 'Has tracking',
        'delivered' => 'Delivered',
    ];

    /**
     * Longest value the varchar(191) columns hold.
     */
    private const MAX_LENGTH = 191;

    /**
     * Signed amount with up to 8 digits and 2 decimals, e.g. "-3.27".
     */
    private const SIGNED_MONEY = '/^-?\d{1,8}(\.\d{1,2})?$/';

    /**
     * Non-negative amount with up to 8 digits and 2 decimals, e.g. "1.55".
     */
    private const MONEY = '/^\d{1,8}(\.\d{1,2})?$/';

    /**
     * Order form fields.
     */
    private const ORDER_FIELDS = [
        'platform', 'order_type', 'order_reference', 'order_date', 'shipping_service', 'postage_cost',
        'packaging_cost', 'shipping_company', 'tracking_code', 'shipping_datetime', 'shipping_delivery_datetime',
        'ebay_user_id', 'full_name', 'email', 'phone', 'street1', 'street2', 'city', 'state', 'postcode',
        'country', 'country_name', 'additional_notes',
    ];

    /**
     * Success messages for `?done=` codes set by redirects.
     */
    private const NOTICES = [
        'created' => 'Order created. Add its items below.',
        'updated' => 'Order updated.',
        'deleted' => 'Order deleted.',
        'item-added' => 'Item added.',
        'item-updated' => 'Item updated.',
        'item-removed' => 'Item removed.',
    ];

    /**
     * Messages for `?error=` codes set by redirects.
     */
    private const ERRORS = [
        'not-connected' => 'Connect your eBay account on the eBay page first.',
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
        private readonly PurchaseCostCalculator $costs,
        private readonly ClockInterface $clock,
        private readonly RecentOrders $ebayOrders,
        private readonly TrackingSynchroniser $tracking,
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * List orders newest first with item totals and profit, filtered by search
     * text, platform, type, date range and tracking, with totals for the filtered
     * set, followed by recent eBay orders that are not imported yet.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request);
    }

    /**
     * Import one recent eBay order (with its items) and return to the list.
     *
     * @param array<string, string> $args Route placeholders, including `orderId`.
     */
    public function importEbayOrder(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $days = RecentOrders::days($this->field($request, 'days'));

        try {
            $order = $this->ebayOrders->import($args['orderId'] ?? '', $this->field($request, 'promoted') === '1');
        } catch (EbayNotConnectedException) {
            return $this->redirect('/orders?error=not-connected');
        } catch (OrderImportException $e) {
            return $this->renderIndex($request, $e->getMessage(), 422, $days);
        } catch (EbayException $e) {
            return $this->renderIndex($request, $e->getMessage(), 502, $days);
        }

        return $this->redirect('/orders?' . http_build_query([
            'done' => 'imported',
            'ref' => $order->getOrderReference(),
            'days' => $days,
        ]));
    }

    /**
     * Fetch tracking and delivery from eBay for eBay orders from the last 30 days that are not delivered.
     */
    public function syncTracking(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $result = $this->tracking->sync();
        } catch (EbayNotConnectedException) {
            return $this->redirect('/orders?error=not-connected');
        } catch (EbayException $e) {
            return $this->renderIndex($request, $e->getMessage(), 502);
        }

        return $this->redirect('/orders?' . http_build_query([
            'done' => 'tracking',
            'checked' => $result['checked'],
            'updated' => $result['updated'],
            'delivered' => $result['delivered'],
            'failed' => count($result['failed']),
        ] + ($result['deliveryError'] !== null ? ['nodelivery' => 1] : [])));
    }

    /**
     * Render the orders list and the recent eBay orders not imported yet.
     *
     * @param int|null $days Look-back period for eBay orders (default: the `days` query parameter).
     */
    private function renderIndex(
        ServerRequestInterface $request,
        ?string $error = null,
        int $status = 200,
        ?int $days = null
    ): ResponseInterface {
        $query = $request->getQueryParams();
        $filters = [
            'q' => is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 100) : '',
            'platform' => in_array($query['platform'] ?? null, self::PLATFORMS, true) ? $query['platform'] : '',
            'type' => in_array($query['type'] ?? null, self::TYPES, true) ? $query['type'] : '',
            'from' => $this->dateParam($query['from'] ?? null),
            'to' => $this->dateParam($query['to'] ?? null),
            'tracking' => is_string($query['tracking'] ?? null) && isset(self::TRACKING_FILTERS[$query['tracking']])
                ? $query['tracking'] : '',
        ];

        [$where, $params] = $this->whereClause($filters);
        $connection = $this->em->getConnection();
        $itemTotals = 'LEFT JOIN (SELECT order_id, COUNT(*) AS items, SUM(quantity) AS units,'
            . ' SUM(sold_price) AS gross, SUM(total_sold_price) AS net, SUM(item_cost * quantity) AS cost'
            . ' FROM order_items GROUP BY order_id) i ON i.order_id = o.id';

        $summaryRow = $connection->fetchAssociative(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(i.net), 0) AS net, COALESCE(SUM(i.cost), 0) AS cost,'
            . ' COALESCE(SUM(o.postage_cost), 0) AS postage, COALESCE(SUM(o.packaging_cost), 0) AS packaging'
            . " FROM orders o $itemTotals $where",
            $params
        ) ?: [];

        $total = (int) ($summaryRow['orders'] ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1));

        $rows = $connection->fetchAllAssociative(
            'SELECT o.id, o.order_reference, o.order_date, o.platform, o.order_type, o.full_name, o.ebay_user_id,'
            . ' o.tracking_code, o.shipping_delivery_datetime, o.postage_cost, o.packaging_cost,'
            . ' COALESCE(i.items, 0) AS items, COALESCE(i.units, 0) AS units, COALESCE(i.gross, 0) AS gross,'
            . ' COALESCE(i.net, 0) AS net,'
            . ' COALESCE(i.cost, 0) AS cost'
            . " FROM orders o $itemTotals $where ORDER BY o.order_date DESC, o.id DESC"
            . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );

        $net = (float) ($summaryRow['net'] ?? 0);
        $cost = (float) ($summaryRow['cost'] ?? 0);
        $shipping = (float) ($summaryRow['postage'] ?? 0) + (float) ($summaryRow['packaging'] ?? 0);
        $products = $this->listProducts(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        return $this->render('orders/index', [
            'orders' => array_map(
                fn (array $row): array => $this->listRow($row) + ['products' => $products[(int) $row['id']] ?? []],
                $rows
            ),
            'summary' => [
                'orders' => $total,
                'net' => $this->money($net),
                'cost' => $this->money($cost),
                'shipping' => $this->money($shipping),
                'profit' => $this->money($net - $cost - $shipping),
            ],
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
            'platforms' => self::PLATFORMS,
            'types' => self::TYPES,
            'trackingFilters' => self::TRACKING_FILTERS,
            'notice' => $this->notice($query),
            'error' => $error ?? (is_string($query['error'] ?? null) ? self::ERRORS[$query['error']] ?? null : null),
            'ebay' => $this->ebayOrders($days ?? RecentOrders::days($query['days'] ?? null)),
        ], $status);
    }

    /**
     * Recent eBay orders not imported yet, for the bottom of the list page.
     *
     * @return array{connected: bool, previews: list<\App\Ebay\Import\ImportPreview>, error: string|null,
     *     days: int, dayOptions: list<int>, trackingDays: int}
     */
    private function ebayOrders(int $days): array
    {
        $ebay = [
            'connected' => $this->ebayOrders->isConnected(),
            'previews' => [],
            'error' => null,
            'days' => $days,
            'dayOptions' => RecentOrders::DAY_OPTIONS,
            'trackingDays' => TrackingSynchroniser::LOOKBACK_DAYS,
        ];

        if ($ebay['connected']) {
            try {
                $ebay['previews'] = $this->ebayOrders->notImported($days);
            } catch (EbayNotConnectedException) {
                $ebay['connected'] = false;
            } catch (EbayException $e) {
                $ebay['error'] = $e->getMessage();
            }
        }

        return $ebay;
    }

    /**
     * Show an order with its items, totals, linked transactions and the add-item form.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $order = $this->findOrder($args);

        if ($order === null) {
            return $this->notFound('Order not found.');
        }

        return $this->renderShow($order, $this->notice($request->getQueryParams()));
    }

    /**
     * Show the form for a new order (eBay purchase, dated now, legacy default shipping).
     */
    public function create(): ResponseInterface
    {
        $values = array_fill_keys(self::ORDER_FIELDS, '');

        return $this->renderOrderForm('orders/create', null, [
            'platform' => 'Ebay',
            'order_type' => 'Purchase',
            'order_date' => $this->clock->now()->format('Y-m-d\TH:i'),
            'shipping_service' => 'CRL48',
            'postage_cost' => '1.55',
            'packaging_cost' => '0.08',
            'country' => 'GB',
            'country_name' => 'United Kingdom',
        ] + $values);
    }

    /**
     * Validate and save a new order, then show it so items can be added.
     */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $values = $this->orderInput($request);
        $errors = $this->validateOrder($values);

        if ($errors !== []) {
            return $this->renderOrderForm('orders/create', null, $values, $errors);
        }

        $order = (new Order())->setStatus(1);
        $this->fillOrder($order, $values);
        $this->em->persist($order);
        $this->em->flush();

        return $this->redirect('/orders/' . $order->getId() . '?done=created');
    }

    /**
     * Show the form for editing an order.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function edit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $order = $this->findOrder($args);

        if ($order === null) {
            return $this->notFound('Order not found.');
        }

        return $this->renderOrderForm('orders/edit', $order, $this->orderValues($order));
    }

    /**
     * Validate and save changes to an order.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $order = $this->findOrder($args);

        if ($order === null) {
            return $this->notFound('Order not found.');
        }

        $values = $this->orderInput($request);
        $errors = $this->validateOrder($values);

        if ($errors !== []) {
            return $this->renderOrderForm('orders/edit', $order, $values, $errors);
        }

        $this->fillOrder($order, $values);
        $order->setUpdated(new DateTime());
        $this->em->flush();

        return $this->redirect('/orders/' . $order->getId() . '?done=updated');
    }

    /**
     * Delete an order and its items. Refused while transactions reference it.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $order = $this->findOrder($args);

        if ($order === null) {
            return $this->notFound('Order not found.');
        }

        $transactions = $this->em->getRepository(Transaction::class)->count(['order' => $order]);

        if ($transactions > 0) {
            return $this->renderShow($order, null, sprintf(
                'This order has %d linked transaction%s, so it cannot be deleted.',
                $transactions,
                $transactions === 1 ? '' : 's'
            ), status: 409);
        }

        foreach ($this->em->getRepository(OrderItem::class)->findBy(['order' => $order]) as $item) {
            $this->em->remove($item);
        }

        $this->em->remove($order);
        $this->em->flush();

        return $this->redirect('/orders?done=deleted');
    }

    /**
     * Add an item to an order. A blank item cost uses the product's FIFO purchase cost.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function addItem(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $order = $this->findOrder($args);

        if ($order === null) {
            return $this->notFound('Order not found.');
        }

        $values = $this->itemInput($request);
        [$errors, $product] = $this->validateItem($values);

        if ($errors !== [] || $product === null) {
            return $this->renderShow($order, null, null, $values, $errors, 422);
        }

        $item = (new OrderItem())->setOrder($order);
        $this->fillItem($item, $product, $values);
        $this->em->persist($item);
        $this->em->flush();

        return $this->redirect('/orders/' . $order->getId() . '?done=item-added');
    }

    /**
     * Show the form for editing an order item.
     *
     * @param array<string, string> $args Route placeholders `id` and `itemId`.
     */
    public function editItem(ServerRequestInterface $request, array $args): ResponseInterface
    {
        [$order, $item] = $this->findItem($args);

        if ($order === null || $item === null) {
            return $this->notFound('Order item not found.');
        }

        return $this->renderItemForm($order, $item, [
            'product' => (string) ($item->getProduct()?->getId() ?? ''),
            'quantity' => (string) ($item->getQuantity() ?? 0),
            'item_cost' => $item->getItemCost() ?? '',
            'sold_price' => $item->getSoldPrice() ?? '0.00',
            'fees_deductions' => $item->getFeesDeductions() ?? '0.00',
            'additional_notes' => $item->getAdditionalNotes() ?? '',
        ]);
    }

    /**
     * Validate and save changes to an order item.
     *
     * @param array<string, string> $args Route placeholders `id` and `itemId`.
     */
    public function updateItem(ServerRequestInterface $request, array $args): ResponseInterface
    {
        [$order, $item] = $this->findItem($args);

        if ($order === null || $item === null) {
            return $this->notFound('Order item not found.');
        }

        $values = $this->itemInput($request);
        [$errors, $product] = $this->validateItem($values);

        if ($errors !== [] || $product === null) {
            return $this->renderItemForm($order, $item, $values, $errors);
        }

        $this->fillItem($item, $product, $values);
        $item->setUpdated(new DateTime());
        $this->em->flush();

        return $this->redirect('/orders/' . $order->getId() . '?done=item-updated');
    }

    /**
     * Remove an item from an order.
     *
     * @param array<string, string> $args Route placeholders `id` and `itemId`.
     */
    public function removeItem(ServerRequestInterface $request, array $args): ResponseInterface
    {
        [$order, $item] = $this->findItem($args);

        if ($order === null || $item === null) {
            return $this->notFound('Order item not found.');
        }

        $this->em->remove($item);
        $this->em->flush();

        return $this->redirect('/orders/' . $order->getId() . '?done=item-removed');
    }

    /**
     * Render an order with its items, totals, transactions and the add-item form.
     *
     * @param array<string, string>|null $itemValues Add-item form values after a failed submit.
     * @param array<string, string> $itemErrors
     */
    private function renderShow(
        Order $order,
        ?string $notice,
        ?string $error = null,
        ?array $itemValues = null,
        array $itemErrors = [],
        int $status = 200
    ): ResponseInterface {
        $items = $this->em->getRepository(OrderItem::class)->findBy(['order' => $order], ['id' => 'ASC']);

        return $this->render('orders/show', [
            'order' => $order,
            'items' => $items,
            'totals' => $this->orderTotals($order, $items),
            'transactions' => $this->em->getRepository(Transaction::class)->findBy(
                ['order' => $order],
                ['transactionDatetime' => 'ASC']
            ),
            'productGroups' => $this->productGroups($itemValues['product'] ?? ''),
            'itemValues' => $itemValues ?? [
                'product' => '',
                'quantity' => '1',
                'item_cost' => '',
                'sold_price' => '',
                'fees_deductions' => '',
                'additional_notes' => '',
            ],
            'itemErrors' => $itemErrors,
            'notice' => $notice,
            'error' => $error,
            'shippingServices' => self::SHIPPING_SERVICES,
        ], $status);
    }

    /**
     * Render the create or edit order form; 422 when there are validation errors.
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderOrderForm(
        string $template,
        ?Order $order,
        array $values,
        array $errors = []
    ): ResponseInterface {
        $services = self::SHIPPING_SERVICES;

        if ($values['shipping_service'] !== '' && !isset($services[$values['shipping_service']])) {
            $services[$values['shipping_service']] = $values['shipping_service'];
        }

        return $this->render($template, [
            'order' => $order,
            'values' => $values,
            'errors' => $errors,
            'platforms' => self::PLATFORMS,
            'types' => self::TYPES,
            'shippingServices' => $services,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Render the item edit form; 422 when there are validation errors.
     *
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function renderItemForm(Order $order, OrderItem $item, array $values, array $errors = []): ResponseInterface
    {
        return $this->render('orders/edit-item', [
            'order' => $order,
            'item' => $item,
            'values' => $values,
            'errors' => $errors,
            'productGroups' => $this->productGroups($values['product'] ?? ''),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Totals for an order: sold, fees, net, item cost, postage, packaging and profit.
     *
     * @param list<OrderItem> $items
     *
     * @return array<string, string|int>
     */
    private function orderTotals(Order $order, array $items): array
    {
        $sum = static fn (callable $value): float => array_sum(array_map($value, $items));
        $net = $sum(static fn (OrderItem $i): float => (float) $i->getTotalSoldPrice());
        $cost = $sum(static fn (OrderItem $i): float => (float) $i->getItemCost() * ($i->getQuantity() ?? 0));
        $postage = (float) $order->getPostageCost();
        $packaging = (float) $order->getPackagingCost();

        return [
            'sold' => $this->money($sum(static fn (OrderItem $i): float => (float) $i->getSoldPrice())),
            'fees' => $this->money($sum(static fn (OrderItem $i): float => (float) $i->getFeesDeductions())),
            'net' => $this->money($net),
            'cost' => $this->money($cost),
            'postage' => $this->money($postage),
            'packaging' => $this->money($packaging),
            'profit' => $this->money($net - $cost - $postage - $packaging),
            'units' => (int) $sum(static fn (OrderItem $i): int => $i->getQuantity() ?? 0),
        ];
    }

    /**
     * Shape a list query row for the view, adding the profit. Gross is the
     * items' sold price before fees; net is after fees; postage and packaging
     * are the order's shipping costs.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function listRow(array $row): array
    {
        $net = (float) $row['net'];
        $postage = (float) $row['postage_cost'];
        $packaging = (float) $row['packaging_cost'];
        $profit = $net - (float) $row['cost'] - $postage - $packaging;

        return [
            'id' => (int) $row['id'],
            'reference' => (string) $row['order_reference'],
            'date' => new DateTime((string) $row['order_date']),
            'platform' => (string) $row['platform'],
            'type' => (string) $row['order_type'],
            'name' => (string) ($row['full_name'] ?? ''),
            'ebayUser' => (string) ($row['ebay_user_id'] ?? ''),
            'tracking' => (string) ($row['tracking_code'] ?? ''),
            'delivered' => !empty($row['shipping_delivery_datetime']),
            'items' => (int) $row['items'],
            'units' => (int) $row['units'],
            'gross' => $this->money((float) $row['gross']),
            'net' => $this->money($net),
            'postage' => $this->money($postage),
            'packaging' => $this->money($packaging),
            'profit' => $this->money($profit),
        ];
    }

    /**
     * Products and quantities for the listed orders, in item order, in one query.
     *
     * @param list<int> $orderIds
     *
     * @return array<int, list<array{title: string, sku: string, quantity: int, cost: string}>> Keyed by
     *     order ID; `cost` is the unit item cost.
     */
    private function listProducts(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT oi.order_id, oi.quantity, oi.item_cost, p.title, p.sku FROM order_items oi'
            . ' LEFT JOIN products p ON p.id = oi.product'
            . ' WHERE oi.order_id IN (' . implode(', ', array_fill(0, count($orderIds), '?')) . ')'
            . ' ORDER BY oi.order_id, oi.id',
            $orderIds
        );

        $products = [];
        foreach ($rows as $row) {
            $products[(int) $row['order_id']][] = [
                'title' => (string) ($row['title'] ?? 'Unknown product'),
                'sku' => (string) ($row['sku'] ?? ''),
                'quantity' => (int) ($row['quantity'] ?? 0),
                'cost' => $this->money((float) ($row['item_cost'] ?? 0)),
            ];
        }

        return $products;
    }

    /**
     * SQL WHERE clause and parameters for the list filters.
     *
     * @param array{q: string, platform: string, type: string, from: string, to: string, tracking: string} $filters
     *
     * @return array{string, list<string>}
     */
    private function whereClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        if ($filters['q'] !== '') {
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $columns = ['order_reference', 'full_name', 'ebay_user_id', 'email', 'postcode', 'tracking_code'];
            $likes = array_map(static fn (string $column): string => "o.$column LIKE ?", $columns);
            $conditions[] = '(' . implode(' OR ', $likes) . ')';
            array_push($params, ...array_fill(0, count($columns), $like));
        }

        foreach (['platform' => 'o.platform', 'type' => 'o.order_type'] as $filter => $column) {
            if ($filters[$filter] !== '') {
                $conditions[] = "$column = ?";
                $params[] = $filters[$filter];
            }
        }

        if ($filters['from'] !== '') {
            $conditions[] = 'o.order_date >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }

        if ($filters['to'] !== '') {
            $conditions[] = 'o.order_date < ?';
            $params[] = (new DateTime($filters['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }

        $conditions[] = match ($filters['tracking']) {
            'none' => "(o.tracking_code IS NULL OR o.tracking_code = '')",
            'tracked' => "(o.tracking_code IS NOT NULL AND o.tracking_code <> '')",
            'delivered' => 'o.shipping_delivery_datetime IS NOT NULL',
            default => '',
        };

        $conditions = array_values(array_filter($conditions));

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Read the order form fields as trimmed strings.
     *
     * @return array<string, string>
     */
    private function orderInput(ServerRequestInterface $request): array
    {
        $values = [];

        foreach (self::ORDER_FIELDS as $field) {
            $values[$field] = $this->field($request, $field);
        }

        return $values;
    }

    /**
     * Current order values as form strings.
     *
     * @return array<string, string>
     */
    private function orderValues(Order $order): array
    {
        $local = static fn (?\DateTimeInterface $date): string => $date?->format('Y-m-d\TH:i') ?? '';

        return [
            'platform' => $order->getPlatform(),
            'order_type' => $order->getOrderType(),
            'order_reference' => $order->getOrderReference(),
            'order_date' => $local($order->getOrderDate()),
            'shipping_service' => $order->getShippingService() ?? '',
            'postage_cost' => $order->getPostageCost(),
            'packaging_cost' => $order->getPackagingCost(),
            'shipping_company' => $order->getShippingCompany() ?? '',
            'tracking_code' => $order->getTrackingCode() ?? '',
            'shipping_datetime' => $local($order->getShippingDatetime()),
            'shipping_delivery_datetime' => $local($order->getShippingDeliveryDatetime()),
            'ebay_user_id' => $order->getEbayUserId() ?? '',
            'full_name' => $order->getFullName() ?? '',
            'email' => $order->getEmail() ?? '',
            'phone' => $order->getPhone() ?? '',
            'street1' => $order->getStreet1() ?? '',
            'street2' => $order->getStreet2() ?? '',
            'city' => $order->getCity() ?? '',
            'state' => $order->getState() ?? '',
            'postcode' => $order->getPostcode() ?? '',
            'country' => $order->getCountry() ?? '',
            'country_name' => $order->getCountryName() ?? '',
            'additional_notes' => $order->getAdditionalNotes() ?? '',
        ];
    }

    /**
     * Validate order fields, returning messages keyed by field.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function validateOrder(array $values): array
    {
        $errors = [];

        if (!in_array($values['platform'], self::PLATFORMS, true)) {
            $errors['platform'] = 'Choose a platform.';
        }

        if (!in_array($values['order_type'], self::TYPES, true)) {
            $errors['order_type'] = 'Choose an order type.';
        }

        if ($values['order_reference'] === '') {
            $errors['order_reference'] = 'Order reference is required.';
        }

        $orderDate = $this->dateTime($values['order_date']);

        if ($orderDate === null) {
            $errors['order_date'] = 'Enter the order date and time.';
        } elseif ($orderDate > DateTime::createFromImmutable($this->clock->now())) {
            $errors['order_date'] = 'The order date cannot be in the future.';
        }

        foreach (['postage_cost' => 'Postage cost', 'packaging_cost' => 'Packaging cost'] as $field => $label) {
            if (!preg_match(self::MONEY, $values[$field])) {
                $errors[$field] = "$label must be an amount like 1.55.";
            }
        }

        $shipped = $values['shipping_datetime'] !== '' ? $this->dateTime($values['shipping_datetime']) : null;
        $delivered = $values['shipping_delivery_datetime'] !== ''
            ? $this->dateTime($values['shipping_delivery_datetime']) : null;

        if ($values['shipping_datetime'] !== '' && $shipped === null) {
            $errors['shipping_datetime'] = 'Enter a valid shipped date and time.';
        } elseif ($shipped !== null && $orderDate !== null && $shipped < $orderDate) {
            $errors['shipping_datetime'] = 'The shipped date cannot be before the order date.';
        }

        if ($values['shipping_delivery_datetime'] !== '' && $delivered === null) {
            $errors['shipping_delivery_datetime'] = 'Enter a valid delivery date and time.';
        } elseif ($delivered !== null && $shipped !== null && $delivered < $shipped) {
            $errors['shipping_delivery_datetime'] = 'The delivery date cannot be before the shipped date.';
        }

        if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }

        if ($values['country'] !== '' && !preg_match('/^[A-Za-z]{2}$/', $values['country'])) {
            $errors['country'] = 'Use a two-letter country code, e.g. GB.';
        }

        foreach (['order_reference', 'shipping_service', 'shipping_company', 'tracking_code'] as $field) {
            if (!isset($errors[$field]) && mb_strlen($values[$field]) > self::MAX_LENGTH) {
                $errors[$field] = 'Must be ' . self::MAX_LENGTH . ' characters or fewer.';
            }
        }

        return $errors;
    }

    /**
     * Copy validated order values onto the entity (blank optional fields become NULL).
     *
     * @param array<string, string> $values
     */
    private function fillOrder(Order $order, array $values): void
    {
        $text = static fn (string $value): ?string => $value === '' ? null : $value;
        $date = fn (string $value): ?DateTime => $value === '' ? null : $this->dateTime($value);

        $order
            ->setPlatform($values['platform'])
            ->setOrderType($values['order_type'])
            ->setOrderReference($values['order_reference'])
            ->setOrderDate($this->dateTime($values['order_date']) ?? new DateTime())
            ->setShippingService($text($values['shipping_service']))
            ->setPostageCost($this->money((float) $values['postage_cost']))
            ->setPackagingCost($this->money((float) $values['packaging_cost']))
            ->setShippingCompany($text($values['shipping_company']))
            ->setTrackingCode($text($values['tracking_code']))
            ->setShippingDatetime($date($values['shipping_datetime']))
            ->setShippingDeliveryDatetime($date($values['shipping_delivery_datetime']))
            ->setEbayUserId($text($values['ebay_user_id']))
            ->setFullName($text($values['full_name']))
            ->setEmail($text($values['email']))
            ->setPhone($text($values['phone']))
            ->setStreet1($text($values['street1']))
            ->setStreet2($text($values['street2']))
            ->setCity($text($values['city']))
            ->setState($text($values['state']))
            ->setPostcode($text(strtoupper($values['postcode'])))
            ->setCountry($text(strtoupper($values['country'])))
            ->setCountryName($text($values['country_name']))
            ->setAdditionalNotes($text($values['additional_notes']));
    }

    /**
     * Read the item form fields as trimmed strings.
     *
     * @return array<string, string>
     */
    private function itemInput(ServerRequestInterface $request): array
    {
        $values = [];

        foreach (['product', 'quantity', 'item_cost', 'sold_price', 'fees_deductions', 'additional_notes'] as $field) {
            $values[$field] = $this->field($request, $field);
        }

        return $values;
    }

    /**
     * Validate item fields.
     *
     * @param array<string, string> $values
     *
     * @return array{array<string, string>, Product|null} Messages keyed by field, and the product.
     */
    private function validateItem(array $values): array
    {
        $errors = [];
        $product = ctype_digit($values['product'])
            ? $this->em->getRepository(Product::class)->find((int) $values['product']) : null;

        if ($product === null) {
            $errors['product'] = 'Choose a product.';
        }

        if (filter_var($values['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            $errors['quantity'] = 'Quantity must be a whole number (0 for refunds without returned units).';
        }

        if ($values['item_cost'] !== '' && !preg_match(self::MONEY, $values['item_cost'])) {
            $errors['item_cost'] = 'Item cost must be an amount like 0.42, or blank to use the purchase cost.';
        }

        foreach (['sold_price' => 'Sold price', 'fees_deductions' => 'Fees'] as $field => $label) {
            if (!preg_match(self::SIGNED_MONEY, $values[$field])) {
                $errors[$field] = "$label must be an amount like 4.99 (negative allowed).";
            }
        }

        return [$errors, $product];
    }

    /**
     * Copy validated item values onto the entity; the total is sold price minus fees.
     *
     * @param array<string, string> $values
     */
    private function fillItem(OrderItem $item, Product $product, array $values): void
    {
        $sold = (float) $values['sold_price'];
        $fees = (float) $values['fees_deductions'];

        $item
            ->setProduct($product)
            ->setQuantity((int) $values['quantity'])
            ->setItemCost($values['item_cost'] !== ''
                ? $this->money((float) $values['item_cost'])
                : $this->costs->unitCost($product))
            ->setSoldPrice($this->money($sold))
            ->setFeesDeductions($this->money($fees))
            ->setTotalSoldPrice($this->money($sold - $fees))
            ->setAdditionalNotes($values['additional_notes'] !== '' ? $values['additional_notes'] : null);
    }

    /**
     * Products grouped by category path for the product select; inactive products are left out
     * unless already selected.
     *
     * @param string $selected Product ID already chosen in the form, kept even when inactive.
     *
     * @return array<string, array<int, string>> Group label => [product ID => "Title (SKU)"].
     */
    private function productGroups(string $selected = ''): array
    {
        $paths = $this->categoryPaths->labels();
        $groups = [];

        foreach ($this->em->getRepository(Product::class)->findBy([], ['title' => 'ASC']) as $product) {
            if ($product->getStatus() !== Product::STATUS_ACTIVE && (string) $product->getId() !== $selected) {
                continue;
            }

            $categoryId = $product->getCategory()?->getId();
            $group = $categoryId !== null ? ($paths[$categoryId] ?? 'Uncategorised') : 'Uncategorised';
            $groups[$group][$product->getId()] = $product->getTitle() . ' (' . $product->getSku() . ')';
        }

        uksort($groups, static fn (string $a, string $b): int => $a === 'Uncategorised' ? 1
            : ($b === 'Uncategorised' ? -1 : strnatcasecmp($a, $b)));

        return $groups;
    }

    /**
     * Find the order named by the `id` route placeholder.
     *
     * @param array<string, string> $args
     */
    private function findOrder(array $args): ?Order
    {
        $id = $args['id'] ?? '';

        return ctype_digit($id) ? $this->em->getRepository(Order::class)->find((int) $id) : null;
    }

    /**
     * Find an order and one of its items; the item must belong to that order.
     *
     * @param array<string, string> $args
     *
     * @return array{Order|null, OrderItem|null}
     */
    private function findItem(array $args): array
    {
        $order = $this->findOrder($args);
        $itemId = $args['itemId'] ?? '';
        $item = $order !== null && ctype_digit($itemId)
            ? $this->em->getRepository(OrderItem::class)->find((int) $itemId) : null;

        return [$order, $item !== null && $item->getOrder() === $order ? $item : null];
    }

    /**
     * Parse a datetime-local value ("2026-11-09T15:30", seconds optional), or null.
     */
    private function dateTime(string $value): ?DateTime
    {
        foreach (['!Y-m-d\TH:i', '!Y-m-d\TH:i:s'] as $format) {
            $date = DateTime::createFromFormat($format, $value);

            if ($date !== false && $date->format(str_replace('!', '', $format)) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * A valid Y-m-d query value, or ''.
     */
    private function dateParam(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $date = DateTime::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * Format an amount as a 2-decimal string.
     */
    private function money(float $amount): string
    {
        return number_format(round($amount, 2) + 0.0, 2, '.', '');
    }

    /**
     * Success message for a `?done=` code, built only from known codes, integers
     * and a validated order reference.
     *
     * @param array<string, mixed> $query
     */
    private function notice(array $query): ?string
    {
        $int = static fn (string $key): int => max(0, (int) ($query[$key] ?? 0));
        $ref = is_string($query['ref'] ?? null) && preg_match('/^[0-9A-Za-z-]{1,40}$/', $query['ref'])
            ? $query['ref'] : '';

        return match ($query['done'] ?? null) {
            'imported' => $ref !== '' ? "eBay order $ref imported." : 'eBay order imported.',
            'tracking' => sprintf(
                'Checked %d eBay order(s): %d updated, %d delivered, %d could not be looked up.%s',
                $int('checked'),
                $int('updated'),
                $int('delivered'),
                $int('failed'),
                isset($query['nodelivery']) ? ' Delivery status could not be fetched from eBay.' : ''
            ),
            default => is_string($query['done'] ?? null) ? self::NOTICES[$query['done']] ?? null : null,
        };
    }

    /**
     * A trimmed string field from the submitted form ('' when missing).
     */
    private function field(ServerRequestInterface $request, string $name): string
    {
        $body = $request->getParsedBody();
        $value = is_array($body) ? ($body[$name] ?? '') : '';

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
