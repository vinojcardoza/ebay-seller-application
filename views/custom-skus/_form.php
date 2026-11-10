<?php

/**
 * Partial: Custom SKU create/edit form.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $action Form action URL.
 * @var string $submitLabel Submit button text.
 * @var string $cancelUrl Where Cancel goes.
 * @var array<string, string> $values Current form values.
 * @var array<string, string> $errors Validation messages keyed by field.
 * @var array<int, string> $categories Category path labels keyed by ID.
 */

use App\Entities\CustomSku;

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
?>
<div class="card">
    <div class="card-body">
        <?php if ($errors !== []) : ?>
            <div class="alert alert-danger" role="alert">Please fix the highlighted fields.</div>
        <?php endif; ?>

        <form method="post" action="<?php echo $this->e($action); ?>" novalidate>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="sku">SKU</label>
                    <input type="text" id="sku" name="sku" maxlength="191" required
                           class="form-control<?php echo $invalid('sku'); ?>" value="<?php echo $this->e($values['sku']); ?>">
                    <?php echo $error('sku'); ?>
                    <div class="form-text">The SKU on the eBay listing. It must not match a product's SKU.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select<?php echo $invalid('status'); ?>">
                        <option value="<?php echo CustomSku::STATUS_ACTIVE; ?>"
                            <?php echo $values['status'] === (string) CustomSku::STATUS_ACTIVE ? 'selected' : ''; ?>>Active</option>
                        <option value="<?php echo CustomSku::STATUS_INACTIVE; ?>"
                            <?php echo $values['status'] === (string) CustomSku::STATUS_INACTIVE ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                    <?php echo $error('status'); ?>
                </div>

                <div class="col-md-6">
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

                <div class="col-md-6">
                    <label class="form-label" for="shipping_service">Shipping service</label>
                    <input type="text" id="shipping_service" name="shipping_service" maxlength="191" placeholder="e.g. CRL48"
                           class="form-control<?php echo $invalid('shipping_service'); ?>"
                           value="<?php echo $this->e($values['shipping_service']); ?>">
                    <?php echo $error('shipping_service'); ?>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="postage_cost">Postage cost (£)</label>
                    <input type="text" inputmode="decimal" id="postage_cost" name="postage_cost"
                           class="form-control<?php echo $invalid('postage_cost'); ?>"
                           value="<?php echo $this->e($values['postage_cost']); ?>">
                    <?php echo $error('postage_cost'); ?>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="package_cost">Packaging cost (£)</label>
                    <input type="text" inputmode="decimal" id="package_cost" name="package_cost"
                           class="form-control<?php echo $invalid('package_cost'); ?>"
                           value="<?php echo $this->e($values['package_cost']); ?>">
                    <?php echo $error('package_cost'); ?>
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
