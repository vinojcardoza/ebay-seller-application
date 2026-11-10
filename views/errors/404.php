<?php

/**
 * View: 404 Not Found page.
 *
 * @var \League\Plates\Template\Template $this
 * @var string $message
 */

$this->layout('layout', ['title' => 'Not found']);
?>
<h1>Not found</h1>

<p><?= $this->e($message) ?></p>

<p><a href="/">Go to the dashboard</a></p>
