<?php

use App\Framework\Template\PlatesRender;
use App\Framework\Template\RendererInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

return [
    StreamFactoryInterface::class => DI\create(Psr17Factory::class),
    ResponseFactoryInterface::class => DI\create(Psr17Factory::class),
    RendererInterface::class => DI\create(PlatesRender::class),
    EntityManagerInterface::class => function () {
        $paths = [dirname(__DIR__) . "/src/Entities"];

        $config = ORMSetup::createAttributeMetadataConfiguration($paths, true);

        $config->enableNativeLazyObjects(true);

        $params = [
            'driver' => 'pdo_mysql',
            'host' => 'mariadb',
            'port' => 3306,
            'dbname' => $_ENV['MARIADB_DATABASE'],
            'user' => $_ENV['MARIADB_USER'],
            'password' => $_ENV['MARIADB_PASSWORD'],
        ];

        $connection = DriverManager::getConnection($params, $config);

        return new EntityManager($connection, $config);
    }
];
