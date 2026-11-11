# JRN Shop — eBay Seller Application

A back-office web application for running a small eBay shop. It keeps the product catalogue, stock purchases, orders and buyer feedback in one MariaDB database, imports orders, tracking and feedback directly from eBay's REST APIs, and reports sales, fees and profit.

It is a lightweight PHP 8.5 MVC application assembled from PSR components rather than a full framework:

| Concern | Library |
| --- | --- |
| Routing | [League Route](https://route.thephpleague.com/) |
| Dependency injection | [PHP-DI](https://php-di.org/) |
| Persistence | [Doctrine ORM](https://www.doctrine-project.org/) (attribute mapping) |
| Templates | [League Plates](https://platesphp.com/) |
| HTTP messages | Guzzle PSR-7, Nyholm PSR-17, HttpSoft SAPI emitter |
| HTTP client (eBay) | Guzzle (PSR-18) |
| Configuration | vlucas/phpdotenv |
| Front end | Bootstrap 5.3, Tabler Icons, Inter (light and dark themes) |

---

## Features

- **Dashboard** — headline business figures (including all-time profit), the current month's sales summary, and products that are low on or out of stock.
- **Products** — searchable catalogue with categories, SKUs, low-stock thresholds and live stock levels (delivered purchases − units sold, plus units on order). Products in use can be set inactive instead of deleted.
- **Categories** — nested categories for organising products.
- **Custom SKUs** — bundles of one or more products sold under a single eBay SKU (e.g. a 3-pack); imported orders expand them into their component products.
- **Purchases** — stock bought from suppliers, with filters and totals; mark purchases delivered one at a time or in bulk. Delivered purchases drive the FIFO item cost of imported orders.
- **Orders** — searchable order list with totals and profit, order details with items and transactions, and full create/edit/delete. Refunds and returns are supported as negative amounts.
- **Reports** — daily (last 30 days) and monthly (last 12 months) sales reports with summary figures, net sales charts and a monthly breakdown. Profit = sold price − fees − item cost × quantity − postage − packaging.
- **Feedback** — buyer feedback summary, daily and monthly charts and a filterable list.
- **eBay integration** (`/ebay`) — connect your seller account with OAuth 2.0 and:
  - import recent orders (real eBay fees split across lines, FIFO item cost, optional promoted-listing fee),
  - sync carrier, tracking number and shipped date,
  - import new buyer feedback incrementally.
  The app only reads from eBay; it never changes your listings or orders.
- **Authentication** — every page requires login; sessions use HttpOnly, SameSite=Lax cookies, regenerate on login and expire after 8 hours of inactivity. Users can change their own password.

---

## Requirements

**With Docker:** Docker Engine / Docker Desktop with Docker Compose v2.

**Without Docker:**

- PHP **8.5** with the `pdo_mysql`, `mbstring`, `json`, `ctype` and `zip` extensions
- MariaDB 10.6+ (11 recommended) or MySQL 8
- A web server: Apache with `mod_rewrite` (`.htaccess` files are included) or nginx with PHP-FPM
- [Composer](https://getcomposer.org/) 2 (a `composer.phar` is included in the repository)
- HTTPS on the public host if you want eBay to redirect back to the app after login

---

## Configuration

All configuration is read from environment variables. Copy the example file and edit it:

```bash
cp .env.example .env
```

| Variable | Required | Description |
| --- | --- | --- |
| `MARIADB_HOST` | yes | Database host. `mariadb` under Docker; e.g. `localhost` or your host's DB server otherwise. |
| `MARIADB_DATABASE` | yes | Database name. |
| `MARIADB_USER` | yes | Database user. |
| `MARIADB_PASSWORD` | yes | Database password. |
| `MARIADB_ROOT_PASSWORD` | Docker only | Root password for the MariaDB container. |
| `EBAY_CLIENT_ID` | for eBay | App ID from the eBay developer portal. |
| `EBAY_CLIENT_SECRET` | for eBay | Cert ID from the eBay developer portal. |
| `EBAY_RU_NAME` | for eBay | RuName (eBay Redirect URL name). |
| `EBAY_ENVIRONMENT` | | `production` (default) or `sandbox`. |
| `EBAY_MARKETPLACE_ID` | | Marketplace, default `EBAY_GB`. |
| `EBAY_SELLER_USERNAME` | for feedback | Your eBay username, used to import feedback. |
| `EBAY_SCOPES` | | OAuth scopes, comma-separated with no spaces. Blank = `api_scope,sell.fulfillment.readonly,commerce.feedback`. |

Notes:

- Quote any value containing spaces (`FULL_NAME="Jane Doe"`); unquoted spaces are a parse error.
- Variables set by the server or Docker take precedence over `.env`.
- `.env` is git-ignored. **Never commit real credentials or tokens.**

---

## Deployment with Docker

The Compose stack runs four services:

| Service | Purpose | URL |
| --- | --- | --- |
| `nginx` | Web server, document root `public/` | http://localhost:8181 |
| `php` | PHP 8.5-FPM with Composer and Node.js | — |
| `mariadb` | MariaDB 11 (data in the `business_app_mariadb_data` volume) | — |
| `phpmyadmin` | Database admin UI | http://localhost:8182 |

### 1. Get the code and configure

```bash
git clone <repository-url> ebay-seller-application
cd ebay-seller-application
cp .env.example .env
# edit .env: set MARIADB_DATABASE, MARIADB_USER, MARIADB_PASSWORD, MARIADB_ROOT_PASSWORD
# keep MARIADB_HOST=mariadb
```

### 2. Build and start the containers

```bash
docker compose up -d --build
```

### 3. Install PHP dependencies

The project directory is mounted into the containers, so install Composer packages inside the `php` container:

```bash
docker compose exec php composer install
```

(Use `composer install --no-dev --optimize-autoloader` for a production server.)

### 4. Create the database schema

Import `business_db_structure.sql` into the database, either:

- **phpMyAdmin:** open http://localhost:8182, log in with your `MARIADB_USER` / `MARIADB_PASSWORD`, select the database and use **Import**; or
- **command line:**

  ```bash
  docker compose exec -T mariadb sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < business_db_structure.sql
  ```

If you have an existing data dump (e.g. from the legacy application), import that instead of — or after — the structure file. phpMyAdmin accepts uploads up to 300 MB.

> There are no migrations. Do not run Doctrine's `orm:schema-tool:update --force`; the schema file is the source of truth.

### 5. Create your first user

```bash
docker compose exec php php bin/set-password.php <login> <email> "Full Name"
```

You are prompted for the password (not echoed). Run the same command with just `<login>` later to reset a password.

### 6. Log in

Open http://localhost:8181 and sign in.

### Day-to-day commands

```bash
docker compose ps                 # status
docker compose logs -f php nginx  # logs
docker compose stop               # stop (data is kept)
docker compose down               # remove containers (database volume is kept)
docker compose down -v            # remove containers AND the database volume
```

### Running Docker on a server

The same stack works on a VPS. For a public deployment:

- Set strong database passwords in `.env`.
- Don't expose phpMyAdmin publicly: remove the `phpmyadmin` service or its `ports:` mapping, or bind it to localhost (`"127.0.0.1:8182:80"`).
- Put a TLS-terminating reverse proxy (Caddy, Traefik, or nginx with Let's Encrypt) in front of port 8181 so the app is served over HTTPS — required for eBay's OAuth redirect.
- Install dependencies with `composer install --no-dev --optimize-autoloader`.
- Back up the `business_app_mariadb_data` volume and `var/ebay/token.json`.

---

## Deployment without Docker (shared hosting, e.g. IONOS, or a plain LAMP/LEMP server)

### 1. Prepare the files

`vendor/` is not in the repository, so install dependencies before uploading (on your machine, or directly on the server if it has SSH and PHP 8.5 CLI):

```bash
git clone <repository-url> ebay-seller-application
cd ebay-seller-application
php composer.phar install --no-dev --optimize-autoloader
```

### 2. Create the database

In your hosting control panel (or with the `mariadb`/`mysql` client) create a database and user, then import `business_db_structure.sql` (and your data dump, if any) — for example through the host's phpMyAdmin, or:

```bash
mariadb -h <db-host> -u <db-user> -p <db-name> < business_db_structure.sql
```

### 3. Configure the environment

Create `.env` in the project root from `.env.example` and set the real database host, name, user and password (and the `EBAY_*` values). `MARIADB_ROOT_PASSWORD` is not needed.

### 4. Upload

Upload the whole project — including `vendor/`, `.env` and both `.htaccess` files — to the server, e.g. via SFTP. Do not upload `node_modules/`, `.git/`, `tests/` or `.phpunit.cache/`.

### 5. Point the web server at the app

**Recommended — document root = `public/`:** if your host lets you choose the document root, set it to the project's `public/` directory. Only `public/` is then reachable from the web; `public/.htaccess` sends every request that isn't an existing file to `index.php`.

**Shared hosting where the document root is the project directory (e.g. IONOS):** leave the document root as the project root. The root `.htaccess` blocks dotfiles (such as `.env` and `.git`) and rewrites every request into `public/`, so this also works. Make sure `mod_rewrite` is enabled and `AllowOverride` permits `.htaccess` (it is by default on IONOS).

**nginx + PHP-FPM:** use a server block like `nginx/default.conf`, adjusting `root` to `/path/to/ebay-seller-application/public` and `fastcgi_pass` to your PHP-FPM socket (e.g. `unix:/run/php/php8.5-fpm.sock`).

Make sure the PHP version for the site is set to **8.5** in the hosting panel.

### 6. Make `var/` writable

The app stores the eBay OAuth token in `var/ebay/token.json` (outside `public/`, mode 0600). Create the directory and let the PHP user write to it:

```bash
mkdir -p var/ebay
chmod 700 var var/ebay   # owned by the PHP/web-server user
```

### 7. Create your first user

With SSH access:

```bash
php bin/set-password.php <login> <email> "Full Name"
```

Without SSH, run the same command locally against the production database (set `MARIADB_HOST` etc. in your local `.env` to the remote database, if your host allows remote connections), or generate a hash locally with `php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), PHP_EOL;'` and insert the user row through phpMyAdmin.

### 8. Enable HTTPS

Turn on an SSL certificate for the domain in your hosting panel (IONOS includes one). HTTPS is required for the eBay OAuth redirect and adds the `Secure` flag to the eBay state cookie.

---

## Connecting eBay

1. Sign in at [developer.ebay.com](https://developer.ebay.com/) and create a **Production** (or Sandbox) keyset under **Application Keys**. Put the App ID in `EBAY_CLIENT_ID` and the Cert ID in `EBAY_CLIENT_SECRET`.
2. Under **User Tokens → Get a Token from eBay via Your Application**, add a RuName (redirect URL name):
   - **Auth accepted URL:** `https://<your-host>/ebay/callback`
   - Put the RuName value in `EBAY_RU_NAME`.
3. Set `EBAY_SELLER_USERNAME` to your eBay username and check `EBAY_MARKETPLACE_ID` (e.g. `EBAY_GB`, `EBAY_US`).
4. Restart so the new environment is picked up (`docker compose up -d` under Docker; nothing needed on shared hosting).
5. In the app, open **eBay** (`/ebay`) and click **Connect**. Sign in to eBay and grant access; you are redirected back and the page shows the connection.

**Running locally over plain HTTP?** eBay only redirects to HTTPS URLs. After granting access, copy the authorization code (or the whole URL eBay landed on) and paste it into the code form on the `/ebay` page.

**`invalid_scope` error?** Your keyset lacks one of the requested scopes — usually `commerce.feedback`. Set `EBAY_SCOPES=api_scope,sell.fulfillment.readonly`, restart and connect again (feedback import will be unavailable).

Tokens are refreshed automatically. Use **Disconnect** on the eBay page to remove the stored token.

---

## Development

Run these inside the `php` container (`docker compose exec php …`) or locally with PHP 8.5:

```bash
composer lint        # phpcbf → phpcs → phpstan → phpunit
composer phpcs       # PSR-12 coding standard check
composer phpcbf      # auto-fix coding standard violations
composer phpstan     # static analysis (level 6)
composer phpunit     # run the test suite
vendor/bin/phpunit --filter testName tests   # run a single test
npm install && npm run watch   # re-run phpcbf/phpcs/phpstan on save
```

### Project layout

```
bin/            CLI scripts (set-password.php)
config/         environment loading, DI definitions, routes
nginx/          nginx config used by Docker
public/         web root: index.php front controller, CSS, JS, images
src/
  Auth/         login, sessions, auth middleware
  Catalog/      category paths, stock levels
  Controllers/  page controllers
  Ebay/         OAuth, REST client, Fulfillment/Feedback APIs, importers
  Entities/     Doctrine entities mapped to the existing schema
  Framework/    base controller, renderer, chart helpers, clock
tests/          PHPUnit tests mirroring src/
views/          Plates templates (layout.php is the admin shell)
var/            runtime data (eBay token) — git-ignored
business_db_structure.sql   database schema
```

Request flow: `public/index.php` → `src/bootstrap.php` builds a PSR-7 request and the PHP-DI container, loads `config/routes.php` into League Route (with `AuthMiddleware` in front of every route except `/login`), dispatches to a controller and emits the response.

---

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `One or more environment variables failed assertions: MARIADB_… is missing` | `.env` is missing or incomplete, or the server can't read it. |
| `SQLSTATE[HY000] [2002]` connection refused | Wrong `MARIADB_HOST` (`mariadb` under Docker, your DB host otherwise), or the database container is still starting. |
| `Table … doesn't exist` | Import `business_db_structure.sql`. |
| 404 for every page except `/` on Apache | `mod_rewrite` is disabled or `.htaccess` overrides aren't allowed. |
| Blank page / 500 | Check PHP is 8.5 and `vendor/` was uploaded; see the PHP error log (`docker compose logs php`). |
| eBay connect fails to save the token | `var/` is not writable by the PHP user. |
| `.env` dotenv parse error | Quote values that contain spaces. |

---

## License

Released under the [MIT License](LICENSE).
