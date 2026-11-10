# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

A hand-rolled PHP MVC app (PHP 8.5, PSR-4 namespace `App\` → `src/`) assembled from PSR components rather than a full framework: League Route for routing, PHP-DI for the container, Doctrine ORM for persistence, League Plates for templates, and HttpSoft's SAPI emitter for output.

## Environment

Runs via Docker Compose (copy `.env.example` to `.env` first):

```bash
docker compose up -d --build
```

- App (nginx → php-fpm): http://localhost:8181 — nginx document root is `public/`, all requests fall through to `public/index.php`
- phpMyAdmin: http://localhost:8182
- MariaDB service hostname is `mariadb` (hardcoded in `config/definitions.php`). `others_business.sql` is a large sample database dump to import (e.g. via phpMyAdmin).

DB credentials reach PHP as container environment variables set by `docker-compose.yml` and are read from `$_ENV`. `vlucas/phpdotenv` is installed but **not** loaded in `bootstrap.php`, so `.env` is only consumed by Compose.

## Commands

```bash
composer lint        # runs phpcbf → phpcs → phpstan → phpunit in sequence
composer phpcs       # PSR-12 check on src, config, tests
composer phpcbf      # auto-fix coding standard violations
composer phpstan     # static analysis, level 6
composer phpunit     # run tests in tests/
vendor/bin/phpunit --filter testName tests   # single test
npm run watch        # gulp: on save of src/config/tests PHP files, run phpcbf → phpcs → phpstan
```

Coding standard is PSR-12 (`phpcs.xml`); the PSR1 side-effects rule is excluded only for `bootstrap.php`. PHPStan ignores `missingType.iterableValue` and `property.onlyRead`. The `tests/` directory currently has no tests.

## Architecture

Request lifecycle: `public/index.php` → `src/bootstrap.php`, which:
1. Builds a PSR-7 request (`GuzzleHttp\Psr7\ServerRequest::fromGlobals()`).
2. Builds the PHP-DI container from `config/definitions.php` with attribute injection enabled.
3. Creates a League `Router` with `ApplicationStrategy` wired to the container — so controllers are resolved and autowired through PHP-DI.
4. Loads `config/routes.php` (returns a closure taking the `Router`) and dispatches.
5. Emits the response with `SapiEmitter`.

Key wiring in `config/definitions.php`:
- `ResponseFactoryInterface` / `StreamFactoryInterface` → Nyholm `Psr17Factory`
- `RendererInterface` → `PlatesRender`
- `EntityManagerInterface` → Doctrine EntityManager using attribute metadata from `src/Entities` (dev mode, native lazy objects)

**Controllers** (`src/Controllers`) extend `App\Framework\Controller\ControllerAbstract`, whose constructor receives the renderer, PSR-17 factories, and `EntityManagerInterface` (exposed as `$this->em`). Actions return `$this->render('dir/template', $args)`, which renders a view into a PSR-7 response. Actions that need route parameters take `(ServerRequestInterface $request, array $args)` — League Route passes placeholders like `{id:number}` in `$args`.

**Adding a page**: add an action to a controller, register it in `config/routes.php`, and add a view under `views/`.

**Views** (`views/`) are Plates templates. Each view calls `$this->layout('layout', ['title' => ...])`; `views/layout.php` outputs `$this->section('content')`. Use `$this->e()` for escaping. `App\Framework\Template\Renderer` is an older plain-PHP (`extract` + `require`) renderer that is not wired into the container.

**Entities** (`src/Entities`) are Doctrine entities mapped with PHP attributes (`#[ORM\Entity]`, `#[ORM\Table]`, etc.) against the existing MariaDB schema; there are no migrations.
