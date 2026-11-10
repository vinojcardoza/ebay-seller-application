<?php

/**
 * Partial: Category create/edit form.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $action Form action URL.
 * @var string $submitLabel Text for the submit button.
 * @var string $cancelUrl Where the Cancel button goes.
 * @var array{title: string, parent_id: string, menu_order: string} $values Current form values.
 * @var array<string, string> $errors Validation messages keyed by field name.
 * @var list<\App\Entities\Category> $parents Categories that may be chosen as parent.
 */

/**
 * Bootstrap class and ARIA attributes for a field, marking it invalid when it has an error.
 */
$invalid = static fn (string $field): string => isset($errors[$field])
    ? ' is-invalid" aria-invalid="true" aria-describedby="' . $field . '-error'
    : '';
?>
<div class="card">
    <div class="card-body">
        <?php if ($errors !== []) : ?>
            <div class="alert alert-danger" role="alert">Please fix the highlighted fields.</div>
        <?php endif; ?>

        <form method="post" action="<?php echo $this->e($action); ?>" novalidate>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="title">Title</label>
                    <input type="text" id="title" name="title" maxlength="191" required
                           class="form-control<?php echo $invalid('title'); ?>"
                           value="<?php echo $this->e($values['title']); ?>">
                    <?php if (isset($errors['title'])) : ?>
                        <div class="invalid-feedback" id="title-error"><?php echo $this->e($errors['title']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="parent_id">Parent category</label>
                    <select id="parent_id" name="parent_id" class="form-select<?php echo $invalid('parent_id'); ?>">
                        <option value="0">None (top level)</option>
                        <?php foreach ($parents as $parent) : ?>
                            <option value="<?php echo $parent->getId(); ?>"
                                <?php echo (string) $parent->getId() === $values['parent_id'] ? 'selected' : ''; ?>>
                                <?php echo $this->e($parent->getTitle()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['parent_id'])) : ?>
                        <div class="invalid-feedback" id="parent_id-error"><?php echo $this->e($errors['parent_id']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="menu_order">Menu order</label>
                    <input type="number" id="menu_order" name="menu_order" step="1"
                           class="form-control<?php echo $invalid('menu_order'); ?>"
                           value="<?php echo $this->e($values['menu_order']); ?>">
                    <?php if (isset($errors['menu_order'])) : ?>
                        <div class="invalid-feedback" id="menu_order-error"><?php echo $this->e($errors['menu_order']); ?></div>
                    <?php endif; ?>
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
