<?php

/**
 * Load environment variables from the project's `.env` file into `$_ENV` / `$_SERVER`.
 *
 * Variables already set by the server (e.g. Docker Compose `environment:`) take
 * precedence, and a missing `.env` file is not an error, so the same code works
 * in Docker and on hosts that only have the `.env` file.
 */

declare(strict_types=1);

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$dotenv->required(['MARIADB_DATABASE', 'MARIADB_USER', 'MARIADB_PASSWORD']);
