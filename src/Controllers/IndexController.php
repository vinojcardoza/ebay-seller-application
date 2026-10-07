<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Framework\Controller\ControllerAbstract;
use Psr\Http\Message\ResponseInterface;

/**
 * Class IndexController
 *
 * @package App\Controllers
 */
class IndexController extends ControllerAbstract
{
    /**
     * @return ResponseInterface
     */
    public function index(): ResponseInterface
    {
        return $this->render('index/index', [
            'name' => 'Vinoj Cardoza'
        ]);
    }
}
