<?php

/**
 * Partial: Purchase create/edit form.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $action Form action URL.
 * @var string $submitLabel Submit button text.
 * @var array<string, string> $values Current form values.
 * @var array<string, string> $errors Validation messages keyed by field.
 * @var array<int, string> $suppliers Supplier names keyed by ID.
 * @var array<string, array<int, string>> $productGroups Category path => [product ID => label].
 * @var array<int, string> $statuses Status labels keyed by value.
 */

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
                <div class="col-12">
                    <label class="form-label" for="product">Product</label>
                    <select id="product" name="product" required class="form-select<?php echo $invalid('product'); ?>">
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

                <div class="col-md-6">
                    <label class="form-label" for="supplier">Supplier</label>
                    <select id="supplier" name="supplier" required class="form-select<?php echo $invalid('supplier'); ?>">
                        <option value="">Choose a supplier…</option>
                        <?php foreach ($suppliers as $id => $name) : ?>
                            <option value="<?php echo $id; ?>" <?php echo (string) $id === $values['supplier'] ? 'selected' : ''; ?>>
                                <?php echo $this->e($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $error('supplier'); ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="purchased_date">Purchase date</label>
                    <input type="date" id="purchased_date" name="purchased_date" required
                           class="form-control<?php echo $invalid('purchased_date'); ?>"
                           value="<?php echo $this->e($values['purchased_date']); ?>">
                    <?php echo $error('purchased_date'); ?>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="quantity">Quantity</label>
                    <input type="number" min="1" step="1" id="quantity" name="quantity" required
                           class="form-control<?php echo $invalid('quantity'); ?>"
                           value="<?php echo $this->e($values['quantity']); ?>">
                    <?php echo $error('quantity'); ?>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="total_price">Total price (£)</label>
                    <input type="text" inputmode="decimal" id="total_price" name="total_price" required placeholder="0.00"
                           class="form-control<?php echo $invalid('total_price'); ?>"
                           value="<?php echo $this->e($values['total_price']); ?>">
                    <?php echo $error('total_price'); ?>
                    <div class="form-text">For all units, including delivery.</div>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="status">Status</label>
                    <select id="status" name="status" class="form-select<?php echo $invalid('status'); ?>">
                        <?php foreach ($statuses as $value => $label) : ?>
                            <option value="<?php echo $value; ?>" <?php echo (string) $value === $values['status'] ? 'selected' : ''; ?>>
                                <?php echo $this->e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $error('status'); ?>
                </div>

                <div class="col-sm-6">
                    <label class="form-label" for="received_date">Received date</label>
                    <input type="date" id="received_date" name="received_date"
                           class="form-control<?php echo $invalid('received_date'); ?>"
                           value="<?php echo $this->e($values['received_date']); ?>">
                    <?php echo $error('received_date'); ?>
                    <div class="form-text">Required when delivered. Item costs use delivered stock, oldest received first.</div>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy" aria-hidden="true"></i> <?php echo $this->e($submitLabel); ?>
                    </button>
                    <a class="btn btn-light-secondary" href="/purchases">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
