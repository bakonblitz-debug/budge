<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v3
- vue (VUE) - v3
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>

<!-- ===================================================================== -->
<!-- PROJECT GUIDELINES — hand-maintained. Lives OUTSIDE the boost block    -->
<!-- above so `boost:update` won't overwrite it. Keep it lean: durable      -->
<!-- decisions only. For live structure use Boost: `route:list`,            -->
<!-- `database-schema`, or just read the files. Don't hardcode what rots.   -->
<!-- ===================================================================== -->
<project-guidelines>

# BudgetApp ("Budge") — Project Guidelines

## What this is
Personal budget web app, porting/expanding an Excel budget workbook into a real app. Personal use now, designed with future SaaS viability in mind. Built for a **Quebec, Canada** user → legal compliance matters (**Loi 25, PIPEDA**).

Stack: PHP 8.3 / Laravel 13 · Vue 3 + Inertia v3 + Vuetify 3 · MySQL 8 · Docker · Vite · Pest v4. (Versions authoritative in the boost block above.)

## Architecture decisions (the durable stuff)

- **Multi-tenancy:** every user-owned table has `user_id`. The `BelongsToUser` trait (`app/Models/Concerns/`) auto-scopes all queries via a global scope and auto-sets `user_id` on create. Put it on every new user-data model — it makes cross-tenant access impossible.
- **Transaction amounts:** signed `DECIMAL(12,2)` — negative = expense, positive = income, so `SUM(amount)` is net balance. Never store absolute value + a type flag.
- **Duplicate detection / import upsert:** SHA-256 **match key** over `date(Y-m-d, day-granular)|description|amount` (time-of-day and `balance_after` are intentionally excluded). Stored in `transactions.hash` — **non-unique** now (indexed `(bank_account_id, hash)`). Use `Transaction::generateHash()` — never compute by hand. Imports are idempotent **positional upserts**: the kth incoming row for a given key matches the kth existing row for that account; matches refresh only bank-sourced fields (`balance_after`, `merchant_name`, and `category_id` only when null) and preserve user edits (`category_id` when set, `notes`, `is_excluded`, `transfer_id`, original `import_batch_id`); surplus rows are created. Re-importing a month is non-destructive (the batch is upserted, not replaced). The importer tracks `imported_count` vs `updated_count`. *(Verify the exact recipe in `Transaction.php` before building on it.)*
- **Statement import (PDF):** banks export PDF statements, parsed with `smalot/pdfparser`. Pipeline lives in `app/Services/Statements/`: `Parsers/*PdfParser` (implement `Contracts/StatementParserInterface`, extend `AbstractStatementParser`) → emit `Dto/ParsedTransaction` → `StatementImporter` → `Transaction` rows under an `ImportBatch`. Parsers are registered in `ParserRegistry` (via `StatementParserServiceProvider`). **To add a bank:** new `*PdfParser` implementing the interface, register it. Monthly batches: re-importing a month upserts that batch (non-destructive) and upserts its transactions (see Duplicate detection above). NBC parsers strip account/cheque numbers for privacy. Request validation: `ImportStatementRequest`.
- **Auto-categorization:** `CategoryRule` model (`matches()`), evaluated by priority (highest first), first match wins. Match types: contains, starts_with, ends_with, exact, regex. The `CategoryMatcher` service loads rules once and matches all imported transactions. *(This rule engine is the deterministic layer the planned Claude categorization-fallback sits behind — see the playbook tasks.)*
- **Category hierarchy:** adjacency list via `parent_id`, max 2 levels (app-enforced).
- **Accrual:** `AccrualCalculator` service — daily proration of fixed expenses, computed in real time from `fixed_expenses` + current date (no table).
- **Envelope budgeting:** `EnvelopePool` accrues monthly; balance = `(months × monthly_accrual) − SUM(spending)`. `current_balance` is a cache; `calculated_balance` accessor is the real value.
- **Life Direction:** YoY comparison in `yearly_snapshots`. Improving (green): savings_rate↑ OR expense_ratio↓. Stable (yellow): within ±2%. Declining (red): savings_rate↓ AND expense_ratio↑.
- **Security (partly planned):** HMAC request signing on state-changing routes (`VerifyHmacSignature` middleware + `HmacService` + `useHmac` composable, gated by `HMAC_ENABLED`). Encryption casts + email blind-index are designed but may not be wired yet — `DB_ENCRYPT` toggle (off in dev). 2FA secrets always encrypted. Verify current state via the code/routes, don't assume.

## Domain tables
15 custom tables (+ Laravel defaults): `user_profiles, bank_accounts, categories, category_rules, import_batches, transactions, budgets, income_entries, fixed_expenses, envelope_pools, savings_milestones, yearly_snapshots, audit_logs, settings`. Use Boost `database-schema` for live columns.

## Conventions
- **Frontend:** Vue 3 `<script setup>` only (never Options API). Vuetify 3 components for everything. MDI icons (`mdi-`). `useForm` from `@inertiajs/vue3`. Navigate with Inertia `router.visit()`, never `<a href>`. `AppLayout.vue` auto-applied. Light/dark themes (green primary). Pages at `resources/js/Pages/{Feature}/Index.vue`.
- **Controllers thin** → delegate to services. Models use `$fillable` (never `$guarded = []`). Constructor property promotion, explicit types, PHP 8.3 features. (PSR-12 via Pint — see boost block.)
- **Testing:** Pest, `RefreshDatabase`, every model has a factory with fluent states (e.g. `Transaction::factory()->expense(42.50)->forBatch($b)->categorized($c)->create()`).

## Local environment
Project root `~/www/budge/`; Laravel in `src/`. Docker via `Makefile` (`make up/down/migrate/test/shell/help`). **Ports are non-standard** (set in root `.env`, not `src/.env`) to avoid colliding with default services on 3306/8080/5173 — Budge uses 8081 (app), 5174 (Vite), 3307 (MySQL).

## Demo profile (fabricated test defaults)
Net ~$1,985 bi-weekly · Quebec · rent $1,725/mo · regular investing · bank: **NBC** (Banque Nationale du Canada). These are the fictional figures `DemoSeeder` builds on — no real financial data lives in the repo.

## Legal & privacy (Loi 25 + PIPEDA)
Data minimization (strip account/cheque numbers on import); minimal PII; data export + cascade delete; encryption toggle for prod; prefer `ca-central-1` (Montreal) hosting; TOTP 2FA planned.

> **AI features (planned, see playbook tasks):** Claude categorization-fallback behind `CategoryMatcher`, LLM-assisted dedup, spending-habit analysis. When building these, send Claude **minimal fields** (description, amount, date) — never account numbers or balances.

</project-guidelines>
