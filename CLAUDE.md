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

Coding standard is PSR-12 (`phpcs.xml`); the PSR1 side-effects rule is excluded only for `bootstrap.php`. PHPStan ignores `missingType.iterableValue` and `property.onlyRead`. `phpunit.xml` bootstraps `vendor/autoload.php`, so a globally installed `phpunit` (e.g. Homebrew) also works from the project root. Tests live in `tests/` under the `Tests\` namespace, mirroring `src/` (e.g. `tests/Entities/ProductTest.php`).

## Doc blocks (required)

Every class, property, constant and method you write or change — in `src/`, `config/` and `tests/`, including constructors, test methods and data providers — must have a `/** ... */` doc block:

- Start with a one-line summary of what it is or does (for tests: the behaviour being verified).
- Add `@param` / `@return` / `@throws` only when they add information the native type doesn't, such as array shapes or iterable value types (e.g. `@return iterable<string, array{string, string}>`).
- For entity properties, describe the column's meaning, not just its name.

Do not leave existing code you touch without doc blocks.

## Tests (required)

Every entity and every controller must have PHPUnit tests. When you add or change one, add or update its tests in the same change, and run `composer phpunit` (all tests must pass) before finishing.

- **Location**: mirror `src/` under `tests/` with a `Test` suffix and `Tests\` namespace — `src/Entities/Product.php` → `tests/Entities/ProductTest.php`, `src/Controllers/CategoriesController.php` → `tests/Controllers/CategoriesControllerTest.php`. Mark classes `final` and add `#[CoversClass(...)]`.
- **Entities**: cover the constructor (e.g. `created` initialised), defaults matching the table's column defaults, every setter/getter round-trip and fluent return, and that nullable setters accept `null`. Follow `tests/Entities/ProductTest.php`.
- **Controllers**: test every action without the database. Build the controller with a real `PlatesRender` and Nyholm `Psr17Factory`, and a PHPUnit mock of `EntityManagerInterface` (plus mocked repositories / DBAL `Connection` as needed). For each action assert the status code, `Location` header on redirects, and key content in the body. Cover the success path and each failure path: not found (404), validation errors (422), refused actions (e.g. 409), and that `persist`/`flush`/`remove` are called (or not) as expected.
- **Data providers**: return arrays (`public static function xProvider(): array { return ['case name' => [...]]; }`), not `yield`; use `#[DataProvider('xProvider')]`.
- Tests need doc blocks too (see above): one line per test saying the behaviour verified.

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

**Views** (`views/`) are Plates templates. Each view calls `$this->layout('layout', ['title' => ..., 'breadcrumbs' => ['Label' => '/url', 'Current' => null]])`; `views/layout.php` is the admin shell (sticky header with the main nav, page header with breadcrumb) and outputs `$this->section('content')`, plus an optional `actions` section (`$this->start('actions')`…`$this->stop()`) for page-header buttons. Use `$this->e()` for escaping. Add header nav links to `$navItems` in the layout. **Never use the short echo tag `<?=`** — always print with `<?php echo ...; ?>`.

**Front end**: Bootstrap 5.3.3 and Tabler Icons (`ti ti-*`) from jsDelivr, Inter from Google Fonts (14px base, theme spacing: 74px header, 25px card padding, 24px card gaps), and the app's own theme in `public/assets/css/app.css` (design tokens on `:root`, dark mode via `data-bs-theme`) and `public/assets/js/app.js` (theme toggle, collapsible header nav, `form[data-confirm]` prompts). Wrap content in `.card` (with `.card-header` + `<h2>`); use `.table.table-hover` inside `.table-responsive`, `.btn-primary` / `.btn-light-{primary,danger,secondary}`, `.badge.tint-{primary,success,warning,danger,info}`, and Bootstrap's `.is-invalid` / `.invalid-feedback` for form errors. For a single-series column chart, insert `views/feedbacks/_column_chart.php` (bars, hover/focus tooltips via `data-tip-value`/`data-tip-label` in `app.js`, and a "Show as table" view). nginx serves files in `public/` directly. `App\Framework\Template\Renderer` is an older plain-PHP (`extract` + `require`) renderer that is not wired into the container.

**eBay integration** (`src/Ebay`, page at `/ebay`) uses eBay's REST APIs with OAuth 2.0 user tokens, replacing the legacy SOAP Trading API plugin (`legacy/.../Controller/Plugin/EbayPlugin.php`):
- `EbayConfig` reads `EBAY_CLIENT_ID`, `EBAY_CLIENT_SECRET`, `EBAY_RU_NAME`, `EBAY_ENVIRONMENT` (`production`|`sandbox`), `EBAY_MARKETPLACE_ID`, `EBAY_SELLER_USERNAME` from `$_ENV` (passed through `docker-compose.yml`; see `.env.example`). **Never hardcode eBay credentials or tokens.**
- `EbayOAuth` runs the authorization-code flow (read-only scopes) and refreshes access tokens; tokens are stored by `FileTokenStore` in `var/ebay/token.json` (git-ignored, mode 0600). `EbayClient` sends authenticated GETs over PSR-18 (Guzzle) and retries once on 401.
- `FulfillmentApi` (Sell Fulfillment v1: orders, shipping fulfillments) and `FeedbackApi` (Commerce Feedback v1) wrap the endpoints.
- `Import\OrderImporter` maps orders to `Order`/`OrderItem` with the legacy rules (custom SKUs expand into products, FIFO item cost via `PurchaseCostCalculator`, optional promoted-listing fee) but real eBay amounts (marketplace fee split across lines with `Money::split`). `Import\TrackingSynchroniser` fills carrier/tracking/shipped date (the REST API has no actual delivery date); `Import\FeedbackImporter` imports buyer feedback incrementally.
- Tests fake HTTP with `Tests\Support\FakeHttpClient` and extend `Tests\Support\EbayTestCase`; time-dependent code takes `App\Framework\Clock\ClockInterface` (`FrozenClock` in tests).

**Entities** (`src/Entities`) are Doctrine entities mapped with PHP attributes (`#[ORM\Entity]`, `#[ORM\Table]`, etc.) against the existing MariaDB schema (`business_db_structure.sql`); there are no migrations. Every table has an entity. Foreign keys are `ManyToOne` associations matching the legacy app (`legacy/module/Application/src/Entity`, the reference for business meaning): `Product`/`CustomSku` → `Category`, `CustomSkuProduct` → `CustomSku` + `Product`, `OrderItem` → `Product` + `Order` (`getOrder()`), `Purchase` → `Supplier` + `Product`, `Transaction` → `Order`; inverse collections are read-only getters (`Category::getProducts()`, `Order::getItems()`/`getTransactions()`, etc.). `Category::$parentId` stays a plain int because 0 means top level. A custom SKU (`/custom-skus`, `CustomSkusController`) bundles one or more products; each `CustomSkuProduct` line's `price` and `fees` are for **all** of that line's units (a 3-pack line is £11.99, not £11.99 each), and a custom SKU must not reuse a product's SKU. Status codes are constants: `Product`/`CustomSku::STATUS_ACTIVE` (1) / `STATUS_INACTIVE` (2), `Purchase::STATUS_PENDING` (0) / `STATUS_DELIVERED` (1). Doctrine's schema diff against the live database is empty; keep it that way, and still don't run `orm:schema-tool:update --force` (there are no migrations).
