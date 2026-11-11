<?php

/**
 * View: Change password form for the logged-in user.
 *
 * @var \League\Plates\Template\Template $this
 * @var array<string, string> $errors Messages keyed by field name.
 * @var string|null $notice Success message.
 * @var int $minLength Shortest allowed password.
 */

$this->layout('layout', ['title' => 'Change password', 'breadcrumbs' => ['Change password' => null]]);

$fields = [
    'current_password' => ['label' => 'Current password', 'autocomplete' => 'current-password', 'help' => null],
    'new_password' => [
        'label' => 'New password',
        'autocomplete' => 'new-password',
        'help' => "At least $minLength characters.",
    ],
    'confirm_password' => ['label' => 'Repeat new password', 'autocomplete' => 'new-password', 'help' => null],
];
?>
<?php if ($notice !== null) : ?>
    <div class="alert alert-success" role="status"><?php echo $this->e($notice); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6 col-xl-5">
        <div class="card">
            <div class="card-header">
                <h2>Change password</h2>
            </div>
            <div class="card-body">
                <form method="post" action="/account/password" novalidate>
                    <?php foreach ($fields as $name => $field) : ?>
                        <?php
                        $error = $errors[$name] ?? null;
                        $described = array_filter([
                            $field['help'] !== null ? $name . '-help' : null,
                            $error !== null ? $name . '-error' : null,
                        ]);
                        ?>
                        <div class="mb-3">
                            <label class="form-label" for="<?php echo $name; ?>"><?php echo $this->e($field['label']); ?></label>
                            <input class="form-control<?php echo $error !== null ? ' is-invalid' : ''; ?>" type="password"
                                   id="<?php echo $name; ?>" name="<?php echo $name; ?>" required
                                   autocomplete="<?php echo $field['autocomplete']; ?>"
                                <?php echo $name === 'new_password' ? 'minlength="' . $minLength . '"' : ''; ?>
                                <?php echo $described !== [] ? 'aria-describedby="' . implode(' ', $described) . '"' : ''; ?>
                                <?php echo $error !== null ? 'aria-invalid="true"' : ''; ?>>
                            <?php if ($field['help'] !== null) : ?>
                                <div class="form-text" id="<?php echo $name; ?>-help"><?php echo $this->e($field['help']); ?></div>
                            <?php endif; ?>
                            <?php if ($error !== null) : ?>
                                <div class="invalid-feedback" id="<?php echo $name; ?>-error"><?php echo $this->e($error); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-key" aria-hidden="true"></i> Change password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
