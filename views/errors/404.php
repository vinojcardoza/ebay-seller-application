<?php

/**
 * View: 404 Not Found page.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $message
 */

$this->layout('layout', ['title' => 'Not found', 'breadcrumbs' => ['Not found' => null]]);
?>
<div class="card">
    <div class="empty-state">
        <i class="ti ti-error-404" aria-hidden="true"></i>
        <p class="mb-3"><?= $this->e($message) ?></p>
        <a class="btn btn-primary" href="/"><i class="ti ti-home" aria-hidden="true"></i> Go to the dashboard</a>
    </div>
</div>
