# BudgetApp

Personal budget management web app built with Laravel 11, Vue 3, Inertia.js, and Vuetify 3.

## Stack

- **Backend:** PHP 8.3 / Laravel 11
- **Frontend:** Vue 3 / Inertia.js / Vuetify 3
- **Database:** MySQL 8
- **Infrastructure:** Docker (nginx, php-fpm, node, mysql)

## Quick Start

```bash
# 1. Clone the repo
git clone git@github.com:your-username/budget-app.git
cd budget-app

# 2. One command does everything
make setup
```

That's it. `make setup` will:
1. Create `.env` from `.env.example`
2. Build the Docker containers
3. Create a fresh Laravel project in `src/`
4. Install Composer dependencies
5. Run migrations and seeders
6. Start Vite dev server with HMR

The app will be available at **http://localhost:8080**.

Vite HMR runs on **http://localhost:5173**.

## Demo data & logging in

`make setup` runs the seeders, which build a self-contained demo user with two
years of fabricated transactions (no real financial data):

- **Email:** `demo@budgetapp.local`
- **Password:** `demo`

Re-seed anytime with `make fresh` (or `php artisan db:seed`).

## Importing statements

Budge imports monthly bank / credit-card **PDF** statements. Two paths:

- **National Bank of Canada (NBC):** dedicated parsers, tuned to NBC's layout.
- **Any other bank:** pick **"Other bank — Auto-detect (PDF, AI)"** on the import
  screen. It reads the statement with Claude, so it needs an `ANTHROPIC_API_KEY`
  in `.env` (account/card numbers are redacted before anything leaves the server).

## Commands

Run `make help` to see all available commands:

| Command | Description |
|---------|-------------|
| `make setup` | **⭐ First-time setup — run this on a fresh clone** |
| `make up` | Start all containers |
| `make down` | Stop all containers |
| `make restart` | Restart all containers |
| `make fresh` | Full reset (destroy data, rebuild, re-seed) |
| `make shell` | Bash into PHP container |
| `make tinker` | Laravel Tinker REPL |
| `make test` | Run test suite (Pest, parallel) |
| `make test-coverage` | Run tests with coverage report |
| `make debug-on` | Enable Xdebug step debugging |
| `make debug-off` | Disable Xdebug |
| `make logs` | Tail all container logs |
| `make logs-laravel` | Tail Laravel log file |
| `make lint` | Run Laravel Pint |
| `make analyse` | Run PHPStan |
| `make check` | Lint + analyse + test |
| `make doctor` | Full project health diagnostics |

## Xdebug

Xdebug is pre-installed but **disabled by default** for performance.

```bash
make debug-on     # Enable step debugging (port 9003)
make debug-off    # Disable
make debug-status # Check current mode
```

**IDE Setup:**
- Listen on port **9003**
- Path mapping: `./src` → `/var/www/html`
- IDE key: `PHPSTORM`

## Project Structure

```
budget-app/
├── Makefile                  ← All commands
├── docker-compose.yml        ← Container orchestration
├── .env.example              ← Environment template
├── docker/
│   ├── nginx/default.conf    ← Nginx config
│   ├── php/
│   │   ├── Dockerfile        ← PHP 8.3 + extensions + Xdebug
│   │   ├── entrypoint.sh     ← Auto: composer install, migrate, seed
│   │   ├── php-custom.ini    ← PHP settings
│   │   └── xdebug.ini        ← Xdebug config (env-driven)
│   └── node/
│       ├── Dockerfile        ← Node 20
│       └── entrypoint.sh     ← Auto: npm install + vite dev
└── src/                      ← Laravel project (created by make init)
```
