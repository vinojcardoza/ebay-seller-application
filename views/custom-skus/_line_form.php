<?php

/**
 * Partial: Product line fields (product, quantity, price, fees) for adding or editing.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $action Form action URL.
 * @var string $submitLabel Submit button text.
 * @var string|null $cancelUrl Where Cancel goes, or null for no Cancel button.
 * @var array{product: string, quantity: string, price: string, fees: string} $values
 * @var array<string, string> $errors Validation messages keyed by field.
 * @var array<int, string> $products Product labels keyed by ID.
 */

/**
 * Bootstrap class and ARIA attributes for a field, marking it invalid when it has an error.
 */
$invalid = static fn (string $field): string => isset($errors[$field])
    ? ' is-invalid" aria-invalid="true" aria-describedby="line-' . $field . '-error'
    : '';

/**
 * Error message element for a field, or nothing.
 */
$error = function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<div class="invalid-feedback" id="line-' . $field . '-error">' . $this->e($errors[$field]) . '</div>'
        : '';
};
?>
<?php if ($errors !== []) : ?>
    <div class="alert alert-danger" role="alert">Please fix the highlighted fields.</div>
<?php endif; ?>

<form method="post" action="<?php echo $this->e($action); ?>" novalidate>
    <div class="row g-3">
        <div class="col-lg-6">
            <label class="form-label" for="line-product">Product</label>
            <select id="line-product" name="product" required class="form-select<?php echo $invalid('product'); ?>">
                <option value="">Choose a product…</option>
                <?php foreach ($products as $id => $label) : ?>
                    <option value="<?php echo $id; ?>" <?php echo (string) $id === $values['product'] ? 'selected' : ''; ?>>
                        <?php echo $this->e($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php echo $error('product'); ?>
        </div>

        <div class="col-sm-4 col-lg-2">
            <label class="form-label" for="line-quantity">Quantity</label>
            <input type="number" min="1" step="1" id="line-quantity" name="quantity"
                   class="form-control<?php echo $invalid('quantity'); ?>" value="<?php echo $this->e($values['quantity']); ?>">
            <?php echo $error('quantity'); ?>
        </div>

        <div class="col-sm-4 col-lg-2">
            <label class="form-label" for="line-price">Price (£)</label>
            <input type="text" inputmode="decimal" id="line-price" name="price" placeholder="0.00"
                   class="form-control<?php echo $invalid('price'); ?>" value="<?php echo $this->e($values['price']); ?>">
            <?php echo $error('price'); ?>
        </div>

        <div class="col-sm-4 col-lg-2">
            <label class="form-label" for="line-fees">Fees (£)</label>
            <input type="text" inputmode="decimal" id="line-fees" name="fees" placeholder="0.00"
                   class="form-control<?php echo $invalid('fees'); ?>" value="<?php echo $this->e($values['fees']); ?>">
            <?php echo $error('fees'); ?>
        </div>

        <div class="col-12">
            <div class="form-text mb-2">Price and fees are for the whole line: all units together (e.g. £11.99 for a 3-pack).</div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="ti ti-device-floppy" aria-hidden="true"></i> <?php echo $this->e($submitLabel); ?>
                </button>
                <?php if ($cancelUrl !== null) : ?>
                    <a class="btn btn-light-secondary" href="<?php echo $this->e($cancelUrl); ?>">Cancel</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>
