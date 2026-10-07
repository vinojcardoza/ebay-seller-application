<?php

declare(strict_types=1);

namespace App\Framework\Template;

use App\Framework\Template\RendererInterface;
use League\Plates\Engine;

class PlatesRender implements RendererInterface
{
    public function render(string $template, array $args = []): string
    {
        $engine = new Engine(dirname(__DIR__, 3) . '/views');

        return $engine->render($template, $args);
    }
}
