<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Catalog\CategoryPaths;
use App\Entities\Category;
use App\Entities\CustomSku;
use App\Entities\CustomSkuProduct;
use App\Entities\Product;
use App\Framework\Controller\ControllerAbstract;
use App\Framework\Template\RendererInterface;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Custom SKUs: listings sold under their own SKU that bundle one or more
 * products (e.g. a regulator plus a heatsink, or a 3-pack of socks).
 *
 * Manages the custom SKU itself and its product lines. A line's price and
 * fees are for all of that line's units, as in the legacy app.
 */
class CustomSkusController extends ControllerAbstract
{
    /**
     * Longest SKU or shipping service the varchar(191) columns hold.
     */
    private const MAX_LENGTH = 191;

    /**
     * Amounts accepted for prices and costs: up to 8 digits and 2 decimals.
     */
    private const MONEY_PATTERN = '/^\d{1,8}(\.\d{1,2})?$/';

    /**
     * Success messages for `?done=` codes set by redirects.
     */
    private const NOTICES = [
        'created' => 'Custom SKU created. Add its products below.',
        'updated' => 'Custom SKU updated.',
        'line-added' => 'Product added to the custom SKU.',
        'line-updated' => 'Product line updated.',
        'line-removed' => 'Product removed from the custom SKU.',
        'deleted' => 'Custom SKU deleted.',
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
    ) {
        parent::__construct($renderer, $stream, $factory, $em);
    }

    /**
     * List custom SKUs with their products, optionally filtered by SKU text and status.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        $status = in_array($query['status'] ?? '', ['1', '2'], true) ? (int) $query['status'] : null;

        $customSkus = $this->customSkus()->findBy([], ['sku' => 'ASC']);
        $this->preloadRelations();

        $filtered = array_values(array_filter(
            $customSkus,
            static fn (CustomSku $sku): bool => ($search === '' || stripos($sku->getSku(), $search) !== false)
                && ($status === null || ($sku->getStatus() ?? CustomSku::STATUS_ACTIVE) === $status)
        ));

        return $this->render('custom-skus/index', [
            'customSkus' => $filtered,
            'total' => count($customSkus),
            'search' => $search,
            'status' => $status,
            'notice' => $this->notice($query),
        ]);
    }

    /**
     * Show a custom SKU, its product lines with totals, and the add-product form.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $customSku = $this->findCustomSku($args);

        if ($customSku === null) {
            return $this->notFound('Custom SKU not found.');
        }

        return $this->renderShow($customSku, $this->notice($request->getQueryParams()));
    }

    /**
     * Show the form for a new custom SKU.
     */
    public function create(): ResponseInterface
    {
        return $this->renderSkuForm('custom-skus/create', null, [
            'sku' => '',
            'category' => '',
            'shipping_service' => '',
            'postage_cost' => '0.00',
            'package_cost' => '0.00',
            'status' => (string) CustomSku::STATUS_ACTIVE,
        ]);
    }

    /**
     * Validate and save a new custom SKU, then show it so products can be added.
     */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $values = $this->skuInput($request);
        $errors = $this->validateSku($values, null);

        if ($errors !== []) {
            return $this->renderSkuForm('custom-skus/create', null, $values, $errors);
        }

        $customSku = new CustomSku();
        $this->fillSku($customSku, $values);
        $this->em->persist($customSku);
        $this->em->flush();

        return $this->redirect('/custom-skus/' . $customSku->getId() . '?done=created');
    }

    /**
     * Show the form for editing a custom SKU.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function edit(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $customSku = $this->findCustomSku($args);

        if ($customSku === null) {
            return $this->notFound('Custom SKU not found.');
        }

        return $this->renderSkuForm('custom-skus/edit', $customSku, [
            'sku' => $customSku->getSku(),
            'category' => (string) ($customSku->getCategory()?->getId() ?? ''),
            'shipping_service' => $customSku->getShippingService() ?? '',
            'postage_cost' => $customSku->getPostageCost(),
            'package_cost' => $customSku->getPackageCost(),
            'status' => (string) ($customSku->getStatus() ?? CustomSku::STATUS_ACTIVE),
        ]);
    }

    /**
     * Validate and save changes to a custom SKU.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $customSku = $this->findCustomSku($args);

        if ($customSku === null) {
            return $this->notFound('Custom SKU not found.');
        }

        $values = $this->skuInput($request);
        $errors = $this->validateSku($values, $customSku);

        if ($errors !== []) {
            return $this->renderSkuForm('custom-skus/edit', $customSku, $values, $errors);
        }

        $this->fillSku($customSku, $values);
        $customSku->setUpdated(new DateTime());
        $this->em->flush();

        return $this->redirect('/custom-skus/' . $customSku->getId() . '?done=updated');
    }

    /**
     * Delete a custom SKU together with its product lines. Past orders are not
     * affected: they reference the products, not the custom SKU.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $customSku = $this->findCustomSku($args);

        if ($customSku === null) {
            return $this->notFound('Custom SKU not found.');
        }

        foreach ($this->lines()->findBy(['customSku' => $customSku]) as $line) {
            $this->em->remove($line);
        }

        $this->em->remove($customSku);
        $this->em->flush();

        return $this->redirect('/custom-skus?done=deleted');
    }

    /**
     * Add a product line to a custom SKU.
     *
     * @param array<string, string> $args Route placeholders, including `id`.
     */
    public function addLine(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $customSku = $this->findCustomSku($args);

        if ($customSku === null) {
            return $this->notFound('Custom SKU not found.');
        }

        $values = $this->lineInput($request);
        [$errors, $product] = $this->validateLine($values, $customSku, null);

        if ($errors !== [] || $product === null) {
            return $this->renderShow($customSku, null, $values, $errors, 422);
        }

        $line = (new CustomSkuProduct())->setCustomSku($customSku)->setProduct($product);
        $this->fillLine($line, $values);
        $this->em->persist($line);
        $this->em->flush();

        return $this->redirect('/custom-skus/' . $customSku->getId() . '?done=line-added');
    }

    /**
     * Show the form for editing one product line.
     *
     * @param array<string, string> $args Route placeholders `id` and `lineId`.
     */
    public function editLine(ServerRequestInterface $request, array $args): ResponseInterface
    {
        [$customSku, $line] = $this->findLine($args);

        if ($customSku === null || $line === null) {
            return $this->notFound('Product line not found.');
        }

        return $this->renderLineForm($customSku, $line, [
            'product' => (string) ($line->getProduct()?->getId() ?? ''),
            'quantity' => (string) ($line->getQuantity() ?? 1),
            'price' => $line->getPrice(),
            'fees' => $line->getFees(),
        ]);
    }

    /**
     * Validate and save changes to a product line.
     *
     * @param array<string, string> $args Route placeholders `id` and `lineId`.
     */
    public function updateLine(ServerRequestInterface $request, array $args): ResponseInterface
    {
        [$customSku, $line] = $this->findLine($args);

        if ($customSku === null || $line === null) {
            return $this->notFound('Product line not found.');
        }

        $values = $this->lineInput($request);
        [$errors, $product] = $this->validateLine($values, $customSku, $line);

        if ($errors !== [] || $product === null) {
            return $this->renderLineForm($customSku, $line, $values, $errors);
        }

        $line->setProduct($product);
        $this->fillLine($line, $values);
        $line->setUpdated(new DateTime());
        $this->em->flush();

        return $this->redirect('/custom-skus/' . $customSku->getId() . '?done=line-updated');
    }

    /**
     * Remove a product line from a custom SKU.
     *
     * @param array<string, string> $args Route placeholders `id` and `lineId`.
     */
    public function removeLine(ServerRequestInterface $request, array $args): ResponseInterface
    {
        [$customSku, $line] = $this->findLine($args);

        if ($customSku === null || $line === null) {
            return $this->notFound('Product line not found.');
        }

        $this->em->remove($line);
        $this->em->flush();

        return $this->redirect('/custom-skus/' . $customSku->getId() . '?done=line-removed');
    }

    /**
     * Render the show page with the bundle's lines, totals and add-product form.
     *
     * @param array{product: string, quantity: string, price: string, fees: string}|null $lineValues
     * @param array<string, string> $lineErrors
     */
    private function renderShow(
        CustomSku $customSku,
        ?string $notice,
        ?array $lineValues = null,
        array $lineErrors = [],
        int $status = 200
    ): ResponseInterface {
        $lines = $this->lines()->findBy(['customSku' => $customSku], ['id' => 'ASC']);

        return $this->render('custom-skus/show', [
            'customSku' => $customSku,
            'lines' => $lines,
            'totals' => $this->totals($customSku, $lines),
            'products' => $this->productOptions($lineValues['product'] ?? ''),
            'lineValues' => $lineValues ?? ['product' => '', 'quantity' => '1', 'price' => '', 'fees' => ''],
            'lineErrors' => $lineErrors,
            'notice' => $notice,
        ], $status);
    }

    /**
     * Render the create or edit form; 422 when there are validation errors.
     *
     * @param array<string, string> $values Current form values.
     * @param array<string, string> $errors Validation messages keyed by field.
     */
    private function renderSkuForm(
        string $template,
        ?CustomSku $customSku,
        array $values,
        array $errors = []
    ): ResponseInterface {
        return $this->render($template, [
            'customSku' => $customSku,
            'values' => $values,
            'errors' => $errors,
            'categories' => $this->categoryPaths->labels(),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Render the product line edit form; 422 when there are validation errors.
     *
     * @param array{product: string, quantity: string, price: string, fees: string} $values
     * @param array<string, string> $errors
     */
    private function renderLineForm(
        CustomSku $customSku,
        CustomSkuProduct $line,
        array $values,
        array $errors = []
    ): ResponseInterface {
        return $this->render('custom-skus/edit-line', [
            'customSku' => $customSku,
            'line' => $line,
            'values' => $values,
            'errors' => $errors,
            'products' => $this->productOptions($values['product']),
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Totals across a bundle's lines, and what is left after postage and packaging.
     *
     * @param list<CustomSkuProduct> $lines
     *
     * @return array{price: string, fees: string, net: string, afterShipping: string, units: int}
     */
    private function totals(CustomSku $customSku, array $lines): array
    {
        $pence = static fn (?string $amount): int => (int) round((float) $amount * 100);
        $price = array_sum(array_map(static fn (CustomSkuProduct $l): int => $pence($l->getPrice()), $lines));
        $fees = array_sum(array_map(static fn (CustomSkuProduct $l): int => $pence($l->getFees()), $lines));
        $shipping = $pence($customSku->getPostageCost()) + $pence($customSku->getPackageCost());
        $format = static fn (int $p): string => number_format($p / 100, 2, '.', '');

        return [
            'price' => $format($price),
            'fees' => $format($fees),
            'net' => $format($price - $fees),
            'afterShipping' => $format($price - $fees - $shipping),
            'units' => array_sum(array_map(static fn (CustomSkuProduct $l): int => $l->getQuantity() ?? 1, $lines)),
        ];
    }

    /**
     * Read the custom SKU form fields as trimmed strings.
     *
     * @return array<string, string>
     */
    private function skuInput(ServerRequestInterface $request): array
    {
        return [
            'sku' => $this->field($request, 'sku'),
            'category' => $this->field($request, 'category'),
            'shipping_service' => $this->field($request, 'shipping_service'),
            'postage_cost' => $this->field($request, 'postage_cost'),
            'package_cost' => $this->field($request, 'package_cost'),
            'status' => $this->field($request, 'status'),
        ];
    }

    /**
     * Read the product line form fields as trimmed strings.
     *
     * @return array{product: string, quantity: string, price: string, fees: string}
     */
    private function lineInput(ServerRequestInterface $request): array
    {
        return [
            'product' => $this->field($request, 'product'),
            'quantity' => $this->field($request, 'quantity'),
            'price' => $this->field($request, 'price'),
            'fees' => $this->field($request, 'fees'),
        ];
    }

    /**
     * Validate custom SKU fields, returning messages keyed by field.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function validateSku(array $values, ?CustomSku $current): array
    {
        $errors = [];
        $sku = $values['sku'];

        if ($sku === '') {
            $errors['sku'] = 'SKU is required.';
        } elseif (mb_strlen($sku) > self::MAX_LENGTH) {
            $errors['sku'] = 'SKU must be ' . self::MAX_LENGTH . ' characters or fewer.';
        } else {
            $existing = $this->customSkus()->findOneBy(['sku' => $sku]);

            if ($existing !== null && $existing !== $current) {
                $errors['sku'] = 'Another custom SKU already uses this SKU.';
            } elseif ($this->em->getRepository(Product::class)->findOneBy(['sku' => $sku]) !== null) {
                $errors['sku'] = 'A product already uses this SKU; eBay orders could not tell them apart.';
            }
        }

        if ($values['category'] !== '' && $this->findById(Category::class, $values['category']) === null) {
            $errors['category'] = 'Choose a valid category.';
        }

        if (mb_strlen($values['shipping_service']) > self::MAX_LENGTH) {
            $errors['shipping_service'] = 'Shipping service must be ' . self::MAX_LENGTH . ' characters or fewer.';
        }

        foreach (['postage_cost' => 'Postage cost', 'package_cost' => 'Packaging cost'] as $field => $label) {
            if (!preg_match(self::MONEY_PATTERN, $values[$field])) {
                $errors[$field] = "$label must be an amount like 1.55.";
            }
        }

        $statuses = [(string) CustomSku::STATUS_ACTIVE, (string) CustomSku::STATUS_INACTIVE];

        if (!in_array($values['status'], $statuses, true)) {
            $errors['status'] = 'Choose a status.';
        }

        return $errors;
    }

    /**
     * Validate product line fields.
     *
     * @param array{product: string, quantity: string, price: string, fees: string} $values
     *
     * @return array{array<string, string>, Product|null} Messages keyed by field, and the chosen product.
     */
    private function validateLine(array $values, CustomSku $customSku, ?CustomSkuProduct $current): array
    {
        $errors = [];
        $product = $values['product'] !== '' ? $this->findById(Product::class, $values['product']) : null;

        if ($product === null) {
            $errors['product'] = 'Choose a product.';
        } else {
            foreach ($this->lines()->findBy(['customSku' => $customSku]) as $line) {
                if ($line !== $current && $line->getProduct() === $product) {
                    $errors['product'] = 'This product is already in the custom SKU; '
                        . 'change that line\'s quantity instead.';
                }
            }
        }

        $quantity = filter_var($values['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($quantity === false) {
            $errors['quantity'] = 'Quantity must be a whole number of 1 or more.';
        }

        foreach (['price' => 'Price', 'fees' => 'Fees'] as $field => $label) {
            if (!preg_match(self::MONEY_PATTERN, $values[$field])) {
                $errors[$field] = "$label must be an amount like 4.99.";
            }
        }

        if (!isset($errors['price'], $errors['fees']) && (float) $values['fees'] > (float) $values['price']) {
            $errors['fees'] = 'Fees cannot be more than the price.';
        }

        return [$errors, $product];
    }

    /**
     * Copy validated custom SKU values onto the entity.
     *
     * @param array<string, string> $values
     */
    private function fillSku(CustomSku $customSku, array $values): void
    {
        $customSku
            ->setSku($values['sku'])
            ->setCategory($values['category'] !== '' ? $this->findById(Category::class, $values['category']) : null)
            ->setShippingService($values['shipping_service'] !== '' ? $values['shipping_service'] : null)
            ->setPostageCost($this->money($values['postage_cost']))
            ->setPackageCost($this->money($values['package_cost']))
            ->setStatus((int) $values['status']);
    }

    /**
     * Copy validated line values (other than the product) onto the entity.
     *
     * @param array{product: string, quantity: string, price: string, fees: string} $values
     */
    private function fillLine(CustomSkuProduct $line, array $values): void
    {
        $line
            ->setQuantity((int) $values['quantity'])
            ->setPrice($this->money($values['price']))
            ->setFees($this->money($values['fees']));
    }

    /**
     * Active products for the select, labelled "Title (SKU)" and sorted by title;
     * an inactive product is only listed when it is already selected.
     *
     * @param string $selected Product ID already chosen in the form, kept even when inactive.
     *
     * @return array<int, string>
     */
    private function productOptions(string $selected = ''): array
    {
        $options = [];

        foreach ($this->em->getRepository(Product::class)->findBy([], ['title' => 'ASC']) as $product) {
            if ($product->getStatus() !== Product::STATUS_ACTIVE && (string) $product->getId() !== $selected) {
                continue;
            }

            $options[$product->getId()] = $product->getTitle() . ' (' . $product->getSku() . ')';
        }

        return $options;
    }

    /**
     * Load every line, product and category once, so the list view does not
     * query them one custom SKU at a time.
     */
    private function preloadRelations(): void
    {
        $this->lines()->findAll();
        $this->em->getRepository(Product::class)->findAll();
        $this->em->getRepository(Category::class)->findAll();
    }

    /**
     * Find the custom SKU named by the `id` route placeholder.
     *
     * @param array<string, string> $args
     */
    private function findCustomSku(array $args): ?CustomSku
    {
        return $this->findById(CustomSku::class, $args['id'] ?? '');
    }

    /**
     * Find a custom SKU and one of its lines from the `id` and `lineId` placeholders.
     * The line must belong to that custom SKU.
     *
     * @param array<string, string> $args
     *
     * @return array{CustomSku|null, CustomSkuProduct|null}
     */
    private function findLine(array $args): array
    {
        $customSku = $this->findCustomSku($args);
        $line = $customSku !== null ? $this->findById(CustomSkuProduct::class, $args['lineId'] ?? '') : null;

        return $line !== null && $line->getCustomSku() === $customSku ? [$customSku, $line] : [$customSku, null];
    }

    /**
     * Find an entity by a numeric ID string, or null for invalid or unknown IDs.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function findById(string $class, string $id): ?object
    {
        return ctype_digit($id) ? $this->em->getRepository($class)->find((int) $id) : null;
    }

    /**
     * Custom SKU repository.
     *
     * @return EntityRepository<CustomSku>
     */
    private function customSkus(): EntityRepository
    {
        return $this->em->getRepository(CustomSku::class);
    }

    /**
     * Custom SKU product line repository.
     *
     * @return EntityRepository<CustomSkuProduct>
     */
    private function lines(): EntityRepository
    {
        return $this->em->getRepository(CustomSkuProduct::class);
    }

    /**
     * A validated amount as a 2-decimal string, e.g. "4" → "4.00".
     */
    private function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Success message for a `?done=` code.
     *
     * @param array<string, mixed> $query
     */
    private function notice(array $query): ?string
    {
        return is_string($query['done'] ?? null) ? self::NOTICES[$query['done']] ?? null : null;
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
