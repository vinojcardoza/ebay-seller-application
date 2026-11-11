<?php

use App\Auth\Authenticator;
use App\Auth\NativeSession;
use App\Auth\SessionInterface;
use App\Ebay\EbayConfig;
use App\Ebay\Token\FileTokenStore;
use App\Ebay\Token\TokenStoreInterface;
use App\Framework\Clock\ClockInterface;
use App\Framework\Clock\SystemClock;
use App\Framework\Template\PlatesRender;
use App\Framework\Template\RendererInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use GuzzleHttp\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

return [
    StreamFactoryInterface::class => DI\create(Psr17Factory::class),
    ResponseFactoryInterface::class => DI\create(Psr17Factory::class),
    RendererInterface::class => fn (Authenticator $auth) => new PlatesRender($auth),
    SessionInterface::class => fn () => new NativeSession(
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    ),
    RequestFactoryInterface::class => DI\create(Psr17Factory::class),
    ClientInterface::class => fn () => new Client(['timeout' => 30, 'connect_timeout' => 10]),
    ClockInterface::class => DI\create(SystemClock::class),
    EbayConfig::class => fn () => EbayConfig::fromEnvironment($_ENV),
    TokenStoreInterface::class => fn () => new FileTokenStore(dirname(__DIR__) . '/var/ebay/token.json'),
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
