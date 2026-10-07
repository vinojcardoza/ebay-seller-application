<?php

declare(strict_types=1);

namespace App\Framework\Template;

/**
 * Interface RendererInterface
 *
 * @package App\Framework
 */
interface RendererInterface
{
    public function render(string $template, array $args = []): string;
}
