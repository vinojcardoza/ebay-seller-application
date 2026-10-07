<?php

declare(strict_types=1);

namespace App\Framework\Template;

use App\Framework\Template\RendererInterface;

/**
 * Class Renderr
 *
 * @package App\Framework
 */
class Renderer implements RendererInterface
{
    /**
     * @return string
     */
    public function render(string $template, array $args = []): string
    {
        extract($args, EXTR_SKIP);

        ob_start();

        require dirname(__DIR__, 3) . "/views/{$template}.php";

        return ob_get_clean();
    }
}
