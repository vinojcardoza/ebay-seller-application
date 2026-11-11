<?php

declare(strict_types=1);

namespace App\Framework\Template;

use App\Auth\Authenticator;
use App\Framework\Template\RendererInterface;
use League\Plates\Engine;

/**
 * Renders League Plates templates from `views/`, sharing the logged-in user
 * with every template as `$currentUser` (null without an authenticator).
 */
class PlatesRender implements RendererInterface
{
    /**
     * Create the renderer.
     */
    public function __construct(private readonly ?Authenticator $auth = null)
    {
    }

    /**
     * Render a template with the given variables.
     */
    public function render(string $template, array $args = []): string
    {
        $engine = new Engine(dirname(__DIR__, 3) . '/views');
        $engine->addData(['currentUser' => $this->auth?->user()]);

        return $engine->render($template, $args);
    }
}
