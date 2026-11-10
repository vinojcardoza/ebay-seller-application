<?php

/**
 * Partial: Order item fields for adding or editing.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $action Form action URL.
 * @var string $submitLabel Submit button text.
 * @var string|null $cancelUrl Where Cancel goes, or null for no Cancel button.
 * @var array<string, string> $values Current form values.
 * @var array<string, string> $errors Validation messages keyed by field.
 * @var array<string, array<int, string>> $productGroups Category path => [product ID => label].
 */

/**
 * Bootstrap class and ARIA attributes for a field, marking it invalid when it has an error.
 */
$invalid = static fn (string $field): string => isset($errors[$field])
    ? ' is-invalid" aria-invalid="true" aria-describedby="item-' . $field . '-error'
    : '';

/**
 * Error message element for a field, or nothing.
 */
$error = function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<div class="invalid-feedback" id="item-' . $field . '-error">' . $this->e($errors[$field]) . '</div>'
        : '';
};
?>
<?php if ($errors !== []) : ?>
    <div class="alert alert-danger" role="alert">Please fix the highlighted fields.</div>
<?php endif; ?>

<form method="post" action="<?php echo $this->e($action); ?>" novalidate>
    <div class="row g-3">
        <div class="col-lg-6">
            <label class="form-label" for="item-product">Product</label>
            <select id="item-product" name="product" required class="form-select<?php echo $invalid('product'); ?>">
                <option value="">Choose a product…</option>
                <?php foreach ($productGroups as $group => $products) : ?>
                    <optgroup label="<?php echo $this->e($group); ?>">
                        <?php foreach ($products as $id => $label) : ?>
                            <option value="<?php echo $id; ?>" <?php echo (string) $id === $values['product'] ? 'selected' : ''; ?>>
                                <?php echo $this->e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <?php echo $error('product'); ?>
        </div>

        <div class="col-sm-6 col-lg-2">
            <label class="form-label" for="item-quantity">Quantity</label>
            <input type="number" min="0" step="1" id="item-quantity" name="quantity"
                   class="form-control<?php echo $invalid('quantity'); ?>" value="<?php echo $this->e($values['quantity']); ?>">
            <?php echo $error('quantity'); ?>
        </div>

        <div class="col-sm-6 col-lg-4">
            <label class="form-label" for="item-cost">Item cost each (£)</label>
            <input type="text" inputmode="decimal" id="item-cost" name="item_cost" placeholder="Blank: purchase cost"
                   class="form-control<?php echo $invalid('item_cost'); ?>" value="<?php echo $this->e($values['item_cost']); ?>">
            <?php echo $error('item_cost'); ?>
        </div>

        <div class="col-sm-6 col-lg-3">
            <label class="form-label" for="item-sold">Sold price (£)</label>
            <input type="text" inputmode="decimal" id="item-sold" name="sold_price" placeholder="0.00"
                   class="form-control<?php echo $invalid('sold_price'); ?>" value="<?php echo $this->e($values['sold_price']); ?>">
            <?php echo $error('sold_price'); ?>
        </div>

        <div class="col-sm-6 col-lg-3">
            <label class="form-label" for="item-fees">Fees (£)</label>
            <input type="text" inputmode="decimal" id="item-fees" name="fees_deductions" placeholder="0.00"
                   class="form-control<?php echo $invalid('fees_deductions'); ?>" value="<?php echo $this->e($values['fees_deductions']); ?>">
            <?php echo $error('fees_deductions'); ?>
        </div>

        <div class="col-lg-6">
            <label class="form-label" for="item-notes">Notes</label>
            <input type="text" id="item-notes" name="additional_notes" class="form-control"
                   value="<?php echo $this->e($values['additional_notes']); ?>">
        </div>

        <div class="col-12">
            <div class="form-text mb-2">
                Sold price and fees are for the whole line. The total is sold price minus fees.
                A blank item cost uses the oldest delivered stock's unit cost.
            </div>
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
