<?php

/**
 * Partial: Product create/edit form.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $action Form action URL.
 * @var string $submitLabel Submit button text.
 * @var string $cancelUrl Where Cancel goes.
 * @var array<string, string> $values Current form values.
 * @var array<string, string> $errors Validation messages keyed by field.
 * @var array<int, string> $categories Category path labels keyed by ID.
 * @var list<string> $shippingServices Shipping services already in use, offered as suggestions.
 */

use App\Entities\Product;

/**
 * Bootstrap class and ARIA attributes for a field, marking it invalid when it has an error.
 */
$invalid = static fn (string $field): string => isset($errors[$field])
    ? ' is-invalid" aria-invalid="true" aria-describedby="' . $field . '-error'
    : '';

/**
 * Error message element for a field, or nothing.
 */
$error = function (string $field) use ($errors): string {
    return isset($errors[$field])
        ? '<div class="invalid-feedback" id="' . $field . '-error">' . $this->e($errors[$field]) . '</div>'
        : '';
};

/**
 * A text input with its label and error message.
 */
$input = function (string $field, string $label, string $extra = '') use ($values, $invalid, $error): string {
    return '<label class="form-label" for="' . $field . '">' . $this->e($label) . '</label>'
        . '<input type="text" id="' . $field . '" name="' . $field . '" ' . $extra
        . ' class="form-control' . $invalid($field) . '" value="' . $this->e($values[$field]) . '">'
        . $error($field);
};
?>
<div class="card">
    <div class="card-body">
        <?php if ($errors !== []) : ?>
            <div class="alert alert-danger" role="alert">Please fix the highlighted fields.</div>
        <?php endif; ?>

        <form method="post" action="<?php echo $this->e($action); ?>" novalidate>
            <div class="row g-3">
                <div class="col-md-8">
                    <?php echo $input('title', 'Title', 'maxlength="191" required'); ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select<?php echo $invalid('status'); ?>">
                        <option value="<?php echo Product::STATUS_ACTIVE; ?>"
                            <?php echo $values['status'] === (string) Product::STATUS_ACTIVE ? 'selected' : ''; ?>>Active</option>
                        <option value="<?php echo Product::STATUS_INACTIVE; ?>"
                            <?php echo $values['status'] === (string) Product::STATUS_INACTIVE ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <?php echo $error('status'); ?>
                    <div class="form-text">Inactive products are hidden from lists and product pickers.</div>
                </div>

                <div class="col-md-4">
                    <?php echo $input('sku', 'SKU', 'maxlength="191" required'); ?>
                    <div class="form-text">The SKU on the eBay listing.</div>
                </div>

                <div class="col-md-4">
                    <?php echo $input('code', 'Code', 'maxlength="191"'); ?>
                </div>

                <div class="col-md-4">
                    <?php echo $input('barcode_upc', 'Barcode / UPC', 'maxlength="191"'); ?>
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="category">Category</label>
                    <select id="category" name="category" class="form-select<?php echo $invalid('category'); ?>">
                        <option value="">None</option>
                        <?php foreach ($categories as $id => $label) : ?>
                            <option value="<?php echo $id; ?>" <?php echo (string) $id === $values['category'] ? 'selected' : ''; ?>>
                                <?php echo $this->e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $error('category'); ?>
                </div>

                <div class="col-md-4">
                    <?php echo $input('low_stock', 'Low stock threshold', 'inputmode="numeric"'); ?>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <?php echo $input('price', 'Selling price (£)', 'inputmode="decimal" required'); ?>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <?php echo $input('fees', 'Fees (£)', 'inputmode="decimal" required'); ?>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <?php echo $input('promo_percentage', 'Promotion (%)', 'inputmode="decimal" required'); ?>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <?php echo $input('shipping_service', 'Shipping service', 'maxlength="191" list="shipping-services" placeholder="e.g. CRL48"'); ?>
                    <datalist id="shipping-services">
                        <?php foreach ($shippingServices as $service) : ?>
                            <option value="<?php echo $this->e($service); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <?php echo $input('postage_cost', 'Postage cost (£)', 'inputmode="decimal" required'); ?>
                </div>

                <div class="col-sm-6 col-lg-4">
                    <?php echo $input('package_cost', 'Packaging cost (£)', 'inputmode="decimal" required'); ?>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy" aria-hidden="true"></i> <?php echo $this->e($submitLabel); ?>
                    </button>
                    <a class="btn btn-light-secondary" href="<?php echo $this->e($cancelUrl); ?>">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
