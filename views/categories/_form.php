<?php

/**
 * Partial: Category create/edit form fields.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $action Form action URL.
 * @var string $submitLabel Text for the submit button.
 * @var array{title: string, parent_id: string, menu_order: string} $values Current form values.
 * @var array<string, string> $errors Validation messages keyed by field name.
 * @var list<\App\Entities\Category> $parents Categories that may be chosen as parent.
 */

/**
 * Attributes marking a field invalid and linking it to its error message.
 */
$invalid = static fn (string $field): string => isset($errors[$field])
    ? ' aria-invalid="true" aria-describedby="' . $field . '-error"'
    : '';
?>
<form method="post" action="<?= $this->e($action) ?>">
    <label for="title">Title</label>
    <input type="text" id="title" name="title" maxlength="191" required
           value="<?= $this->e($values['title']) ?>"<?= $invalid('title') ?>>
    <?php if (isset($errors['title'])) : ?>
        <small id="title-error"><?= $this->e($errors['title']) ?></small>
    <?php endif; ?>

    <label for="parent_id">Parent category</label>
    <select id="parent_id" name="parent_id"<?= $invalid('parent_id') ?>>
        <option value="0">None (top level)</option>
        <?php foreach ($parents as $parent) : ?>
            <option value="<?= $parent->getId() ?>"
                <?= (string) $parent->getId() === $values['parent_id'] ? 'selected' : '' ?>>
                <?= $this->e($parent->getTitle()) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($errors['parent_id'])) : ?>
        <small id="parent_id-error"><?= $this->e($errors['parent_id']) ?></small>
    <?php endif; ?>

    <label for="menu_order">Menu order</label>
    <input type="number" id="menu_order" name="menu_order" step="1"
           value="<?= $this->e($values['menu_order']) ?>"<?= $invalid('menu_order') ?>>
    <?php if (isset($errors['menu_order'])) : ?>
        <small id="menu_order-error"><?= $this->e($errors['menu_order']) ?></small>
    <?php endif; ?>

    <button type="submit"><?= $this->e($submitLabel) ?></button>
</form>
