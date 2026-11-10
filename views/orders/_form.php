<?php

/**
 * Partial: Order create/edit form.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $action Form action URL.
 * @var string $submitLabel Submit button text.
 * @var string $cancelUrl Where Cancel goes.
 * @var array<string, string> $values Current form values.
 * @var array<string, string> $errors Validation messages keyed by field.
 * @var list<string> $platforms
 * @var list<string> $types
 * @var array<string, string> $shippingServices Labels keyed by code.
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

/**
 * A text input with its label and error.
 */
$input = function (string $field, string $label, string $type = 'text', string $col = 'col-md-6') use ($values, $invalid, $error): string {
    return '<div class="' . $col . '"><label class="form-label" for="' . $field . '">' . $this->e($label) . '</label>'
        . '<input type="' . $type . '" id="' . $field . '" name="' . $field . '" class="form-control' . $invalid($field) . '"'
        . ' value="' . $this->e($values[$field]) . '">' . $error($field) . '</div>';
};
?>
<?php if ($errors !== []) : ?>
    <div class="alert alert-danger" role="alert">Please fix the highlighted fields.</div>
<?php endif; ?>

<form method="post" action="<?php echo $this->e($action); ?>" novalidate>
    <div class="card">
        <div class="card-header"><h2>Order</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="platform">Platform</label>
                    <select id="platform" name="platform" class="form-select<?php echo $invalid('platform'); ?>">
                        <?php foreach ($platforms as $platform) : ?>
                            <option value="<?php echo $platform; ?>" <?php echo $values['platform'] === $platform ? 'selected' : ''; ?>><?php echo $platform; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $error('platform'); ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="order_type">Type</label>
                    <select id="order_type" name="order_type" class="form-select<?php echo $invalid('order_type'); ?>">
                        <?php foreach ($types as $type) : ?>
                            <option value="<?php echo $type; ?>" <?php echo $values['order_type'] === $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $error('order_type'); ?>
                </div>
                <?php echo $input('order_date', 'Order date', 'datetime-local', 'col-md-4'); ?>
                <?php echo $input('order_reference', 'Order reference', 'text', 'col-md-6'); ?>
                <div class="col-md-6">
                    <label class="form-label" for="shipping_service">Shipping service</label>
                    <select id="shipping_service" name="shipping_service" class="form-select<?php echo $invalid('shipping_service'); ?>">
                        <option value="">None</option>
                        <?php foreach ($shippingServices as $code => $label) : ?>
                            <option value="<?php echo $this->e($code); ?>" <?php echo $values['shipping_service'] === (string) $code ? 'selected' : ''; ?>><?php echo $this->e($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php echo $error('shipping_service'); ?>
                </div>
                <?php echo $input('postage_cost', 'Postage cost (£)', 'text', 'col-sm-6 col-md-3'); ?>
                <?php echo $input('packaging_cost', 'Packaging cost (£)', 'text', 'col-sm-6 col-md-3'); ?>
                <?php echo $input('shipping_company', 'Carrier', 'text', 'col-md-6'); ?>
                <?php echo $input('tracking_code', 'Tracking code', 'text', 'col-md-6'); ?>
                <?php echo $input('shipping_datetime', 'Shipped', 'datetime-local', 'col-md-3'); ?>
                <?php echo $input('shipping_delivery_datetime', 'Delivered', 'datetime-local', 'col-md-3'); ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Buyer</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <?php echo $input('full_name', 'Full name'); ?>
                <?php echo $input('ebay_user_id', 'eBay user ID'); ?>
                <?php echo $input('email', 'Email', 'email'); ?>
                <?php echo $input('phone', 'Phone', 'tel'); ?>
                <?php echo $input('street1', 'Street 1'); ?>
                <?php echo $input('street2', 'Street 2'); ?>
                <?php echo $input('city', 'City', 'text', 'col-md-4'); ?>
                <?php echo $input('state', 'County / state', 'text', 'col-md-4'); ?>
                <?php echo $input('postcode', 'Postcode', 'text', 'col-md-4'); ?>
                <?php echo $input('country', 'Country code', 'text', 'col-md-4'); ?>
                <?php echo $input('country_name', 'Country name', 'text', 'col-md-8'); ?>
                <div class="col-12">
                    <label class="form-label" for="additional_notes">Notes</label>
                    <textarea id="additional_notes" name="additional_notes" rows="3" class="form-control"><?php echo $this->e($values['additional_notes']); ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-device-floppy" aria-hidden="true"></i> <?php echo $this->e($submitLabel); ?>
                    </button>
                    <a class="btn btn-light-secondary" href="<?php echo $this->e($cancelUrl); ?>">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>
