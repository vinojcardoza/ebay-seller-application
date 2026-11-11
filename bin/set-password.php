#!/usr/bin/env php
<?php

/**
 * Set a user's password, or create a user, from the command line.
 *
 * Usage (inside the PHP container):
 *   docker compose exec php php bin/set-password.php <login-or-email>
 *   docker compose exec php php bin/set-password.php <login> <email> [full name]   # creates the user if missing
 *
 * The password is read from the terminal without echoing (or from stdin when piped).
 */

declare(strict_types=1);

use App\Auth\Authenticator;
use App\Entities\User;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/environment.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$identifier = trim($argv[1] ?? '');
$email = trim($argv[2] ?? '');
$fullName = trim($argv[3] ?? '');

if ($identifier === '') {
    fwrite(STDERR, "Usage: php bin/set-password.php <login-or-email> [<email> [full name]]\n");
    exit(1);
}

/**
 * Read a line from the terminal without echoing it.
 */
$readSecret = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $tty = stream_isatty(STDIN);

    if ($tty) {
        shell_exec('stty -echo');
    }

    $value = rtrim((string) fgets(STDIN), "\r\n");

    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }

    return $value;
};

$builder = new DI\ContainerBuilder();
$builder->addDefinitions(dirname(__DIR__) . '/config/definitions.php');
$container = $builder->build();

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);
/** @var Authenticator $auth */
$auth = $container->get(Authenticator::class);

$users = $em->getRepository(User::class);
$user = $users->findOneBy(['login' => $identifier]) ?? $users->findOneBy(['email' => $identifier]);
$creating = $user === null;

if ($creating) {
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        fwrite(STDERR, "No user \"$identifier\". To create one, pass a valid email: <login> <email> [full name]\n");
        exit(1);
    }

    if ($users->findOneBy(['email' => $email]) !== null) {
        fwrite(STDERR, "Another user already has the email $email.\n");
        exit(1);
    }

    $user = (new User())
        ->setLogin($identifier)
        ->setEmail($email)
        ->setFullName($fullName !== '' ? $fullName : null)
        ->setHash(bin2hex(random_bytes(16)));
}

$password = $readSecret('New password: ');

if ($password !== $readSecret('Repeat password: ')) {
    fwrite(STDERR, "The passwords do not match.\n");
    exit(1);
}

try {
    $auth->setPassword($user, $password);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($creating) {
    $em->persist($user);
}

$em->flush();

fwrite(STDOUT, ($creating ? 'Created user ' : 'Password updated for ') . $user->getLogin() . ".\n");
