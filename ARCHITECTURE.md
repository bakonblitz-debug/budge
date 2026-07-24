# BudgetApp — Architecture & Schema Document

**Stack:** Laravel 11 · Vue 3 · Inertia.js · Vuetify 3 · MySQL 8 · Docker  
**Target Bank (MVP):** Banque Nationale du Canada (NBC)  
**Auth:** Laravel Fortify + 2FA (TOTP) — multi-tenant ready

---

## 1. Docker Stack

| Container   | Image / Base        | Port  | Purpose                          |
|-------------|---------------------|-------|----------------------------------|
| `nginx`     | nginx:alpine        | 8080  | Reverse proxy, serves static     |
| `app`       | php:8.3-fpm         | 9000  | Laravel (PHP-FPM) + Xdebug      |
| `mysql`     | mysql:8.0           | 3306  | Database                         |
| `node`      | node:20-alpine      | 5173  | Vite dev server (HMR, auto-start)|

Volumes:
- `./src` → `/var/www/html` (app + node containers)
- `mysql_data` → `/var/lib/mysql` (named volume, persistent)
- `./docker/nginx/default.conf` → nginx config
- `./docker/php/xdebug.ini` → `/usr/local/etc/php/conf.d/xdebug.ini`

---

## 2. Developer Experience (DX)

### 2.1 One-Command Startup

Everything runs with a single command. No manual steps.

```bash
make up          # docker compose up -d --build → all containers start
                 # node container auto-runs: npm install && npm run dev
                 # app container auto-runs: composer install, migrate, seed
make down        # tear down
make fresh       # full reset: down, rebuild, fresh migrate + seed
make logs        # tail all container logs
make logs-app    # tail just php/laravel logs
make shell       # bash into the app container
make test        # run full test suite
make test-watch  # run tests on file change (fswatch)
```

A `Makefile` at project root wraps all docker compose commands. No one should have to remember raw docker commands.

**Node container entrypoint:**
```bash
#!/bin/sh
cd /var/www/html
npm install
npm run dev -- --host 0.0.0.0
```

This means: on `docker compose up`, npm dependencies are installed and Vite starts automatically with HMR exposed to the host. No manual `npm run dev` needed ever.

**App container entrypoint:**
```bash
#!/bin/sh
composer install --no-interaction
php artisan migrate --force
php artisan db:seed --force
php-fpm
```

### 2.2 Xdebug

Xdebug 3 installed and pre-configured in the PHP container. Toggled via environment variable so it doesn't slow things down when you don't need it.

**`docker/php/xdebug.ini`:**
```ini
[xdebug]
xdebug.mode=${XDEBUG_MODE:-off}
xdebug.start_with_request=yes
xdebug.client_host=host.docker.internal
xdebug.client_port=9003
xdebug.log=/var/log/xdebug.log
xdebug.idekey=PHPSTORM
```

**Usage:**
```bash
make debug-on    # sets XDEBUG_MODE=debug, restarts app container
make debug-off   # sets XDEBUG_MODE=off, restarts app container
```

**`.env` toggle:**
```
XDEBUG_MODE=off          # default: off for performance
# XDEBUG_MODE=debug      # step debugging
# XDEBUG_MODE=profile    # profiling
# XDEBUG_MODE=coverage   # code coverage for tests
```

PHPStorm / VS Code just needs to listen on port 9003. Path mappings: `./src` → `/var/www/html`.

### 2.3 Logging

Multiple layers of logging, all easily accessible from the host.

**Laravel Log Channels (`config/logging.php`):**
```
- daily       → storage/logs/laravel-YYYY-MM-DD.log (default)
- import      → storage/logs/import.log (CSV import events)
- audit       → storage/logs/audit.log (all audit trail writes)
- query       → storage/logs/query.log (slow queries, optional)
```

**Custom log channel per domain:**
```php
// In CsvImporter.php
Log::channel('import')->info('Batch started', ['batch_id' => $id, 'file' => $name]);
Log::channel('import')->warning('Duplicate skipped', ['hash' => $hash, 'row' => $row]);
Log::channel('import')->error('Parse failed', ['row' => $row, 'error' => $e->getMessage()]);
```

**Laravel Telescope** (dev only):
- Installed in dev, disabled in production via `TelescopeServiceProvider`
- Available at `/telescope` — shows requests, queries, jobs, logs, exceptions, dumps
- Query tab shows every SQL query with time + bindings — essential for optimizing

**Docker log access:**
```bash
make logs              # all containers
make logs-app          # php-fpm + Laravel logs
make logs-query        # slow query log
docker compose logs mysql -f   # MySQL logs directly
```

**Log files mounted to host:**
- `./src/storage/logs/` is on your host filesystem — open in any editor, tail in terminal, etc.

### 2.4 Testing

**Framework:** Pest PHP (built on PHPUnit, cleaner syntax, Laravel-native)

**Test structure:**
```
tests/
├── Unit/
│   ├── Services/
│   │   ├── CsvImporterTest.php
│   │   ├── NbcParserTest.php
│   │   ├── CategoryMatcherTest.php
│   │   ├── AccrualCalculatorTest.php
│   │   ├── EnvelopePoolServiceTest.php
│   │   └── StatisticsEngineTest.php
│   └── Models/
│       ├── TransactionTest.php
│       ├── CategoryTest.php
│       └── BudgetTest.php
├── Feature/
│   ├── ImportCsvTest.php
│   ├── CategoryManagementTest.php
│   ├── BudgetTrackingTest.php
│   ├── EnvelopePoolTest.php
│   ├── DashboardTest.php
│   └── AuditLogTest.php
└── Fixtures/
    ├── nbc_sample.csv
    ├── nbc_duplicates.csv
    └── nbc_malformed.csv
```

**Testing principles:**
- Every service class has unit tests with mocked dependencies
- Every controller has feature tests using Laravel's HTTP testing
- CSV parser has fixture files for each bank format
- Database tests use `RefreshDatabase` trait (test DB wiped between tests)
- Factories for all models (`TransactionFactory`, `CategoryFactory`, etc.)

**Makefile test commands:**
```bash
make test              # pest --parallel
make test-unit         # pest --testsuite=Unit
make test-feature      # pest --testsuite=Feature
make test-coverage     # pest --coverage (requires XDEBUG_MODE=coverage)
make test-filter F=xyz # pest --filter=xyz
```

**CI-ready:** Tests run with `XDEBUG_MODE=coverage` for coverage reports. SQLite in-memory for speed. Test database config in `phpunit.xml`.

### 2.5 Code Quality

```bash
make lint              # Laravel Pint (PSR-12 + Laravel preset)
make analyse           # PHPStan level 6
make check             # lint + analyse + test (run before committing)
```

---

## 3. Authentication & Security

### 3.1 Auth Stack

**Laravel Fortify** for authentication (headless — no UI opinions, we build our own with Vuetify + Inertia).

| Feature              | Implementation                              |
|----------------------|---------------------------------------------|
| Registration         | Email + password (bcrypt, cost 12)          |
| Login                | Email + password → 2FA challenge            |
| 2FA                  | TOTP (Google Authenticator, Authy, etc.)    |
| Password reset       | Email-based token, 60min expiry             |
| Email verification   | Required before first use                   |
| Session management   | Server-side sessions (database driver)      |
| Rate limiting        | 5 login attempts / minute per IP            |
| Remember me          | Optional, 30-day encrypted cookie           |

### 3.2 Two-Factor Authentication (TOTP)

Mandatory 2FA for all accounts — not optional. Since we store real financial numbers, every account must be protected.

**Setup flow:**
1. User registers with email + password
2. Immediately prompted to set up 2FA
3. App generates TOTP secret → shows QR code (Vuetify dialog)
4. User scans with authenticator app (Google Authenticator, Authy, 1Password, etc.)
5. User enters 6-digit code to confirm setup
6. Recovery codes generated (8 single-use codes) — user must save these
7. Account is now active

**Login flow:**
1. Email + password → validated
2. 2FA challenge screen → enter 6-digit TOTP code
3. If code valid → session created
4. If lost authenticator → recovery code input option

**Recovery codes:**
- 8 single-use codes generated at 2FA setup
- Stored as hashed values (bcrypt) — plaintext shown only once
- Each code can only be used once
- User can regenerate all codes from settings (invalidates old ones)

### 3.3 Multi-Tenancy

Every data table gets a `user_id` FK from the start. All queries scoped via Laravel Global Scopes.

```php
// App\Models\Concerns\BelongsToUser.php (trait)
protected static function booted()
{
    static::addGlobalScope('user', function ($query) {
        $query->where('user_id', auth()->id());
    });

    static::creating(function ($model) {
        $model->user_id = auth()->id();
    });
}
```

Every model uses this trait. It's impossible to accidentally query another user's data.

**Tables that get `user_id`:**
`bank_accounts`, `categories`, `category_rules`, `import_batches`, `transactions`, `budgets`, `income_entries`, `fixed_expenses`, `envelope_pools`, `savings_milestones`, `yearly_snapshots`, `settings`, `audit_logs`

### 3.4 Session Security

| Setting                    | Value                    | Reason                            |
|----------------------------|--------------------------|------------------------------------|
| `SESSION_DRIVER`           | `database`               | Queryable, revocable, scalable    |
| `SESSION_LIFETIME`         | `120` (2 hours)          | Auto-logout after inactivity      |
| `SESSION_SECURE_COOKIE`    | `true`                   | HTTPS only                        |
| `SESSION_HTTP_ONLY`        | `true`                   | No JS access to session cookie    |
| `SESSION_SAME_SITE`        | `lax`                    | CSRF protection                   |
| `SESSION_ENCRYPT`          | `true`                   | Encrypted session data            |

---

## 4. Legal & Privacy Compliance

### 4.1 Applicable Laws

Since this is hosted in Quebec, Canada, and handles financial data:

| Law                        | Scope                     | Key Requirements                  |
|----------------------------|---------------------------|------------------------------------|
| **Loi 25 (Law 25)**        | Quebec provincial         | Privacy by default, consent, breach notification, privacy officer, DPIAs |
| **PIPEDA**                 | Federal Canada            | Consent, limited collection, retention limits, access rights |
| **CASL**                   | Federal Canada            | Anti-spam — applies if sending emails (notifications, marketing) |

### 4.2 What We Store vs. What We Don't

**We DO store (per user):**
- Email address (encrypted at rest when `DB_ENCRYPT=true`, blind index for lookups)
- Hashed password (bcrypt — always hashed regardless of DB_ENCRYPT)
- 2FA secret (always encrypted regardless of DB_ENCRYPT)
- Budget configuration (categories, amounts, targets)
- Income amounts and dates
- Transaction amounts, dates, descriptions (from CSV imports)
- Bank account names and types (user-defined labels, not actual account numbers)

**We NEVER store:**
- Real bank account numbers (CSV parser strips `Numéro de compte` column on import)
- Social insurance numbers, government IDs
- Bank login credentials
- Credit card numbers
- Full names (email-only registration)
- Physical addresses
- IP addresses in audit logs (optional, disabled by default)

### 4.3 Data Minimization

The CSV parser is the critical boundary. When importing NBC CSVs:
- `Numéro de compte` → **DISCARDED**, never stored
- `Type de compte` → **DISCARDED**, user assigns their own account label
- `Chèque #` → **DISCARDED**
- Only `Date`, `Description`, `Débit/Crédit`, `Solde` are kept

The `description` field (e.g. "PAIEMENT - IGA EXPRESS") does contain merchant names, which could be considered personal information under Loi 25. We mitigate this by:
- Encrypting the `description` column at rest (see 4.5)
- Never sharing transaction data across users
- Providing a data export + full delete option

### 4.4 User Rights (Loi 25 / PIPEDA)

The app must support these rights — we build them into the Settings page:

| Right                      | Implementation                              |
|----------------------------|---------------------------------------------|
| **Right to access**        | "Export My Data" → JSON/CSV download of all user data |
| **Right to rectification** | Users can edit all their own data directly   |
| **Right to deletion**      | "Delete My Account" → full cascade delete of all data |
| **Right to portability**   | Export includes all data in machine-readable format |
| **Consent**                | Registration requires explicit consent checkbox (not pre-checked) |
| **Breach notification**    | If self-hosted: N/A. If SaaS: must notify within 72 hours |

**Account deletion flow:**
1. User clicks "Delete My Account" in settings
2. Confirmation dialog with password + 2FA code required
3. All user data cascade-deleted: transactions, categories, budgets, income, pools, milestones, snapshots, audit logs, sessions
4. Email sent confirming deletion
5. User record soft-deleted for 30 days (legal hold), then hard-deleted via scheduled job

### 4.5 Encryption

| Layer                      | Method                    | What's Protected                  |
|----------------------------|---------------------------|------------------------------------|
| **In transit**             | TLS 1.3 (HTTPS)          | All traffic between client and server |
| **At rest (DB)**           | Env-toggled via `DB_ENCRYPT=true` | Sensitive fields encrypted on write, decrypted on read |
| **Blind indexes**          | HMAC-SHA256 with separate key | Deterministic lookup hashes for encrypted queryable fields |
| **At rest (disk)**         | MySQL tablespace encryption (optional) | Full database files      |
| **Backups**                | GPG encrypted             | Database dumps                    |
| **Session data**           | `SESSION_ENCRYPT=true`   | Session payload                   |
| **2FA secrets**            | Always encrypted (not toggled) | `two_factor_secret`, recovery codes |

**Environment toggle — `DB_ENCRYPT`:**

```env
# .env
DB_ENCRYPT=false    # Development: plaintext, inspect DB freely, run raw queries
DB_ENCRYPT=true     # Production: all sensitive fields encrypted at rest
```

**Implementation — custom cast that checks the env:**

```php
// App\Casts\EncryptableString.php
class EncryptableString implements CastsAttributes
{
    public function get($model, $key, $value, $attributes)
    {
        if (!config('app.db_encrypt') || is_null($value)) return $value;
        try { return Crypt::decryptString($value); }
        catch (DecryptException $e) { return $value; } // fallback for pre-encryption data
    }

    public function set($model, $key, $value, $attributes)
    {
        if (!config('app.db_encrypt') || is_null($value)) return $value;
        return Crypt::encryptString($value);
    }
}

// App\Casts\EncryptableDecimal.php — same pattern, casts to/from float
```

**Usage on models:**
```php
// Transaction.php
protected $casts = [
    'description'  => EncryptableString::class,
    'amount'       => EncryptableDecimal::class,
    'balance_after' => EncryptableDecimal::class,
];
```

**Fields encrypted when `DB_ENCRYPT=true`:**

| Model              | Fields                                       |
|--------------------|----------------------------------------------|
| `User`             | `email` (+ blind index on `email_index`)     |
| `Transaction`      | `description`, `amount`, `balance_after`     |
| `IncomeEntry`      | `amount`, `source`, `notes`                  |
| `FixedExpense`     | `amount`, `name`                             |
| `EnvelopePool`     | `monthly_accrual`, `current_balance`         |

**Migration path:** When flipping `DB_ENCRYPT` from `false` to `true` in production, run an artisan command that reads all existing plaintext rows and re-saves them (triggering the cast to encrypt). The `DecryptException` catch in the getter means the app won't break if some rows are already plain while others are encrypted — it gracefully handles mixed states.

```bash
php artisan db:encrypt-existing    # One-time migration command
```

**Why this approach:**
- Dev is painless — `DB_ENCRYPT=false`, query the DB directly, inspect values, debug with ease
- Production is secure — flip one env var, run the migration command, done
- No code changes between environments — same models, same logic
- Graceful fallback — handles mixed encrypted/plaintext data during transition

### 4.6 Privacy Policy & Terms (for SaaS)

If commercialized, we'll need:
- **Privacy Policy** — what we collect, why, how long, who has access, user rights
- **Terms of Service** — liability limitations (we are not financial advisors), data accuracy disclaimers
- **Cookie Policy** — session cookies only, no tracking
- **DPIA (Data Protection Impact Assessment)** — required by Loi 25 for handling financial data

**Loi 25 specific requirements:**
- Designate a **privacy officer** (can be yourself for a small company)
- Publish privacy policy on the website
- Conduct a DPIA before launch
- Maintain a **privacy incident register**
- Implement **privacy by default** — minimum data collection, maximum protection

### 4.7 Hosting Considerations (for SaaS)

| Option                     | Legal Implication                            |
|----------------------------|---------------------------------------------|
| **Self-hosted in Canada**  | Simplest — data stays in Canada, full Loi 25 + PIPEDA compliance |
| **Canadian cloud (AWS ca-central-1, GCP northamerica-northeast1)** | Data in Canada, shared responsibility model |
| **US cloud**               | Triggers cross-border transfer rules under Loi 25 — requires equivalent protection assessment + user consent |

**Recommendation:** Host in `ca-central-1` (Montreal) if going SaaS. Data stays in Quebec, no cross-border headaches.

---

## 5. Directory Structure

```
budget-app/
├── Makefile                      ← All commands: make up, make test, make debug-on, etc.
├── docker-compose.yml
├── docker-compose.override.yml   ← Local overrides (gitignored)
├── docker/
│   ├── nginx/
│   │   └── default.conf
│   ├── php/
│   │   ├── Dockerfile
│   │   ├── entrypoint.sh         ← Auto: composer install, migrate, seed, php-fpm
│   │   └── xdebug.ini
│   └── node/
│       ├── Dockerfile
│       └── entrypoint.sh         ← Auto: npm install && npm run dev
└── src/                          ← Laravel project root
    ├── app/
    │   ├── Models/
    │   ├── Http/Controllers/
    │   ├── Services/
    │   │   ├── CsvImporter.php
    │   │   ├── Parsers/
    │   │   │   ├── CsvParserInterface.php
    │   │   │   └── NbcParser.php
    │   │   ├── CategoryMatcher.php
    │   │   ├── BudgetCalculator.php
    │   │   ├── AccrualCalculator.php
    │   │   ├── EnvelopePoolService.php
    │   │   └── StatisticsEngine.php
    │   └── Enums/
    │       ├── TransactionType.php
    │       ├── IncomeFrequency.php
    │       ├── ExpenseType.php
    │       └── AuditAction.php
    ├── resources/
    │   └── js/
    │       ├── app.js
    │       ├── Layouts/
    │       │   └── AppLayout.vue
    │       └── Pages/
    │           ├── Auth/
    │           │   ├── Login.vue
    │           │   ├── Register.vue
    │           │   ├── TwoFactorChallenge.vue
    │           │   ├── TwoFactorSetup.vue
    │           │   ├── ForgotPassword.vue
    │           │   ├── ResetPassword.vue
    │           │   └── VerifyEmail.vue
    │           ├── Dashboard.vue
    │           ├── Transactions/
    │           │   ├── Index.vue
    │           │   └── Import.vue
    │           ├── Categories/
    │           │   └── Index.vue
    │           ├── Income/
    │           │   └── Index.vue
    │           ├── FixedExpenses/
    │           │   └── Index.vue
    │           ├── Budgets/
    │           │   └── Index.vue
    │           ├── EnvelopePools/
    │           │   └── Index.vue
    │           ├── Accrual/
    │           │   └── Index.vue
    │           ├── Statistics/
    │           │   └── Index.vue
    │           ├── Milestones/
    │           │   └── Index.vue
    │           ├── Settings/
    │           │   └── Index.vue
    │           └── Audit/
    │               └── Index.vue
    ├── tests/
    │   ├── Unit/
    │   │   ├── Services/
    │   │   └── Models/
    │   ├── Feature/
    │   └── Fixtures/
    │       ├── nbc_sample.csv
    │       ├── nbc_duplicates.csv
    │       └── nbc_malformed.csv
    └── ...
```

---

## 6. Database Schema

### 6.1 `users`

Standard Laravel users table with 2FA fields and encrypted email via blind index.

| Column                    | Type                          | Notes                              |
|---------------------------|-------------------------------|------------------------------------|
| `id`                      | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `email`                   | `TEXT`                        | Encrypted email (EncryptableString cast) |
| `email_index`             | `VARCHAR(255)` UNIQUE         | HMAC-SHA256 blind index for lookups |
| `email_verified_at`       | `TIMESTAMP` NULLABLE          | Must verify before using app       |
| `password`                | `VARCHAR(255)`                | Bcrypt hash, cost 12               |
| `two_factor_secret`       | `TEXT` NULLABLE (encrypted)   | TOTP secret, always encrypted      |
| `two_factor_recovery_codes` | `TEXT` NULLABLE (encrypted) | JSON array of hashed recovery codes |
| `two_factor_confirmed_at` | `TIMESTAMP` NULLABLE          | When 2FA setup was completed       |
| `remember_token`          | `VARCHAR(100)` NULLABLE       |                                    |
| `created_at`              | `TIMESTAMP`                   |                                    |
| `updated_at`              | `TIMESTAMP`                   |                                    |

**Unique constraint:** `email_index` (not `email` — encrypted values aren't deterministic)

**Blind index — how it works:**

A blind index lets you query encrypted data without decrypting it. The `email` column holds the encrypted value (unreadable without `APP_KEY`). The `email_index` column holds a keyed HMAC hash — deterministic but irreversible — used solely for `WHERE` lookups.

```php
// App\Casts\EncryptableEmail.php
class EncryptableEmail implements CastsAttributes
{
    public function get($model, $key, $value, $attributes)
    {
        if (!config('app.db_encrypt') || is_null($value)) return $value;
        try { return Crypt::decryptString($value); }
        catch (DecryptException $e) { return $value; }
    }

    public function set($model, $key, $value, $attributes)
    {
        if (is_null($value)) return ['email' => null, 'email_index' => null];

        return [
            'email'       => config('app.db_encrypt')
                ? Crypt::encryptString(strtolower($value))
                : strtolower($value),
            'email_index' => hash_hmac('sha256', strtolower($value), config('app.blind_index_key')),
        ];
    }
}
```

```env
# .env — separate key from APP_KEY for defense in depth
BLIND_INDEX_KEY=your-random-64-char-hex-key
```

**Login query (works whether encrypted or not):**
```php
// When DB_ENCRYPT=true: looks up by HMAC hash
$user = User::where('email_index', hash_hmac('sha256', $email, config('app.blind_index_key')))->first();

// When DB_ENCRYPT=false: email_index is still populated, same query works
```

**What an attacker sees if the DB leaks:**

| Column         | Value (DB_ENCRYPT=true)                              |
|----------------|------------------------------------------------------|
| `email`        | `eyJpdiI6Ik1UZ3hO...` (AES-256-CBC gibberish)       |
| `email_index`  | `a3f8b2c1d4e5...` (HMAC hash — can't reverse to email) |
| `password`     | `$2y$12$...` (bcrypt — already secure)               |
| `2fa_secret`   | `eyJpdiI6Ik5Ua3...` (always encrypted)               |

Without both `APP_KEY` and `BLIND_INDEX_KEY`, the email is unrecoverable. Even with the HMAC hash, they'd need the `BLIND_INDEX_KEY` to brute-force emails, which is computationally impractical for arbitrary addresses.

**`BLIND_INDEX_KEY` must be different from `APP_KEY`** — if an attacker gets one, they don't automatically get the other. Store them separately (e.g. different secret managers, different env injection paths in production).

### 6.2 `sessions`

Database-backed sessions for security and revocability.

| Column         | Type                          | Notes                              |
|----------------|-------------------------------|------------------------------------|
| `id`           | `VARCHAR(255)` PK             | Session ID                         |
| `user_id`      | `BIGINT UNSIGNED` NULLABLE FK |                                    |
| `ip_address`   | `VARCHAR(45)` NULLABLE        |                                    |
| `user_agent`   | `TEXT` NULLABLE               |                                    |
| `payload`      | `LONGTEXT`                    | Encrypted session data             |
| `last_activity`| `INT`                         | Unix timestamp                     |

**Index:** `user_id`, `last_activity`

---

### 6.3 `bank_accounts`

Represents a bank account you track (chequing, savings, credit card, etc.)

| Column       | Type                          | Notes                              |
|--------------|-------------------------------|------------------------------------|
| `id`         | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`    | `BIGINT UNSIGNED` FK → `users.id` | Owner — global scope enforced  |
| `name`       | `VARCHAR(255)`                | e.g. "NBC Chequing"               |
| `type`       | `ENUM('chequing','savings','credit_card','other')` |               |
| `currency`   | `CHAR(3)` DEFAULT `'CAD'`    |                                    |
| `is_active`  | `BOOLEAN` DEFAULT `true`     |                                    |
| `created_at` | `TIMESTAMP`                   |                                    |
| `updated_at` | `TIMESTAMP`                   |                                    |

**Index:** `user_id`

---

### 6.4 `categories`

Hierarchical categories with parent/child support (adjacency list).

| Column       | Type                          | Notes                              |
|--------------|-------------------------------|------------------------------------|
| `id`         | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`    | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `parent_id`  | `BIGINT UNSIGNED` NULLABLE FK → `categories.id` | NULL = root category |
| `name`       | `VARCHAR(255)`                | e.g. "Groceries"                   |
| `icon`       | `VARCHAR(50)` NULLABLE        | Vuetify mdi icon name              |
| `color`      | `VARCHAR(7)` NULLABLE         | Hex color for charts               |
| `sort_order` | `INT` DEFAULT `0`            | Display ordering                   |
| `is_active`  | `BOOLEAN` DEFAULT `true`     |                                    |
| `created_at` | `TIMESTAMP`                   |                                    |
| `updated_at` | `TIMESTAMP`                   |                                    |

**Index:** `parent_id`  
**Constraint:** max 2 levels deep (enforced in application logic)

Example hierarchy:
```
Housing (parent)
  ├── Rent
  ├── Electricity
  └── Internet
Food (parent)
  ├── Groceries
  ├── Restaurants
  └── Coffee
Transportation (parent)
  ├── STM Pass
  ├── Gas
  └── Uber
```

---

### 6.5 `category_rules`

Auto-categorization rules applied during CSV import.

| Column         | Type                          | Notes                              |
|----------------|-------------------------------|------------------------------------|
| `id`           | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`      | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `category_id`  | `BIGINT UNSIGNED` FK → `categories.id` |                          |
| `match_type`   | `ENUM('contains','starts_with','ends_with','exact','regex')` |     |
| `match_value`  | `VARCHAR(255)`                | e.g. "IGA", "METRO", "STM"        |
| `priority`     | `INT` DEFAULT `0`            | Higher = checked first             |
| `is_active`    | `BOOLEAN` DEFAULT `true`     |                                    |
| `created_at`   | `TIMESTAMP`                   |                                    |
| `updated_at`   | `TIMESTAMP`                   |                                    |

**Index:** `category_id`, `priority DESC`

When importing, rules are evaluated in priority order. First match wins.

---

### 6.6 `import_batches`

Tracks each CSV upload as a batch.

| Column              | Type                          | Notes                              |
|---------------------|-------------------------------|------------------------------------|
| `id`                | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`           | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `bank_account_id`   | `BIGINT UNSIGNED` FK → `bank_accounts.id` |                        |
| `period_year`       | `SMALLINT UNSIGNED`           | e.g. 2025                          |
| `period_month`      | `TINYINT UNSIGNED`            | 1–12                               |
| `filename`          | `VARCHAR(255)`                | Original uploaded filename         |
| `bank_format`       | `VARCHAR(50)` DEFAULT `'nbc'` | Parser identifier                  |
| `total_rows`        | `INT`                         | Total CSV rows processed           |
| `imported_count`    | `INT`                         | Rows actually inserted             |
| `duplicate_count`   | `INT`                         | Rows skipped as duplicates         |
| `skipped_count`     | `INT`                         | Rows skipped for other reasons     |
| `status`            | `ENUM('pending','processing','completed','failed')` |              |
| `error_message`     | `TEXT` NULLABLE               | If status = failed                 |
| `imported_at`       | `TIMESTAMP`                   | When import completed              |
| `created_at`        | `TIMESTAMP`                   |                                    |
| `updated_at`        | `TIMESTAMP`                   |                                    |

**Unique constraint:** `(bank_account_id, period_year, period_month)` — one import per account per month.  
**Index:** `bank_account_id`, `status`  
**Note:** Re-importing the same month replaces the previous batch (soft-delete old, insert new). This ensures monthly data is always a clean snapshot.

---

### 6.7 `transactions`

Core table — every financial transaction, primarily from CSV imports.

| Column              | Type                          | Notes                              |
|---------------------|-------------------------------|------------------------------------|
| `id`                | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`           | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `bank_account_id`   | `BIGINT UNSIGNED` FK → `bank_accounts.id` |                        |
| `import_batch_id`   | `BIGINT UNSIGNED` NULLABLE FK → `import_batches.id` | NULL = manual entry |
| `category_id`       | `BIGINT UNSIGNED` NULLABLE FK → `categories.id` | NULL = uncategorized |
| `transaction_date`  | `DATETIME`                    | When the transaction occurred      |
| `description`       | `VARCHAR(500)`                | Raw description from bank          |
| `amount`            | `DECIMAL(12,2)`               | **Signed:** negative = expense, positive = income |
| `balance_after`     | `DECIMAL(12,2)` NULLABLE      | Running balance if provided by bank|
| `notes`             | `TEXT` NULLABLE               | User-added notes                   |
| `is_excluded`       | `BOOLEAN` DEFAULT `false`     | Exclude from budget calculations (transfers, etc.) |
| `hash`              | `VARCHAR(64)` UNIQUE          | SHA-256 of epoch(datetime)+description+amount+balance_after |
| `created_at`        | `TIMESTAMP`                   |                                    |
| `updated_at`        | `TIMESTAMP`                   |                                    |

**Indexes:** `bank_account_id`, `category_id`, `transaction_date`, `hash` (unique)

**Duplicate detection:** On import, we convert `transaction_date` to epoch (Unix timestamp), then compute `SHA-256(epoch + description + amount + balance_after)`. Using the epoch adds an extra layer of obfuscation — the hash reveals nothing about the original values. Including `balance_after` ensures truly identical-looking transactions (e.g. two $5 coffees at the same place) are still treated as unique, since the running balance differs after each one.

**Note on CSV dates:** Most bank CSVs only provide a date (no time). When no time component is present, we default to `00:00:00`. If the bank provides timestamps, we preserve the full precision.

---

### 6.8 `budgets`

Monthly spending targets per category.

| Column         | Type                          | Notes                              |
|----------------|-------------------------------|------------------------------------|
| `id`           | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`      | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `category_id`  | `BIGINT UNSIGNED` FK → `categories.id` |                          |
| `amount`       | `DECIMAL(10,2)`               | Target amount (positive value)     |
| `period`       | `ENUM('monthly','weekly','yearly')` DEFAULT `'monthly'` |          |
| `start_date`   | `DATE`                        | When this budget takes effect      |
| `end_date`     | `DATE` NULLABLE               | NULL = ongoing                     |
| `is_active`    | `BOOLEAN` DEFAULT `true`     |                                    |
| `created_at`   | `TIMESTAMP`                   |                                    |
| `updated_at`   | `TIMESTAMP`                   |                                    |

**Unique constraint:** `(category_id, start_date)` — one budget per category per period.  
**Index:** `category_id`, `is_active`

---

### 6.9 `audit_logs`

Polymorphic audit trail of all changes.

| Column           | Type                          | Notes                              |
|------------------|-------------------------------|------------------------------------|
| `id`             | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`        | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `auditable_type` | `VARCHAR(255)`                | Model class (e.g. `App\Models\Transaction`) |
| `auditable_id`   | `BIGINT UNSIGNED`             | PK of the changed record           |
| `action`         | `ENUM('created','updated','deleted','imported','categorized','excluded')` | |
| `old_values`     | `JSON` NULLABLE               | Previous values (for updates)      |
| `new_values`     | `JSON` NULLABLE               | New values                         |
| `metadata`       | `JSON` NULLABLE               | Extra context (batch_id, source, etc.) |
| `created_at`     | `TIMESTAMP`                   |                                    |

**Indexes:** `(auditable_type, auditable_id)`, `action`, `created_at`

---

### 6.10 `settings`

Key-value store for app-level configuration.

| Column       | Type                          | Notes                              |
|--------------|-------------------------------|------------------------------------|
| `id`         | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`    | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `key`        | `VARCHAR(255)` UNIQUE         | e.g. "default_currency", "fiscal_year_start" |
| `value`      | `TEXT`                        | Stored as string, cast in app      |
| `type`       | `VARCHAR(20)` DEFAULT `'string'` | string, integer, boolean, json  |
| `created_at` | `TIMESTAMP`                   |                                    |
| `updated_at` | `TIMESTAMP`                   |                                    |

---

### 6.11 `income_entries`

Salary and other income tracking. Supports bi-weekly salary with batch entry (enter multiple pay dates at once, even months later — same pattern as the Excel Salary Log).

| Column         | Type                          | Notes                              |
|----------------|-------------------------------|------------------------------------|
| `id`           | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`      | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `source`       | `VARCHAR(255)`                | e.g. "Employer - Salary", "Freelance" |
| `amount`       | `DECIMAL(12,2)`               | Gross or net amount (positive)     |
| `frequency`    | `ENUM('bi_weekly','weekly','monthly','one_time')` | Pay frequency    |
| `pay_date`     | `DATE`                        | Actual date of this pay            |
| `is_net`       | `BOOLEAN` DEFAULT `true`     | true = after-tax, false = gross    |
| `notes`        | `TEXT` NULLABLE               | e.g. "Overtime included"           |
| `created_at`   | `TIMESTAMP`                   |                                    |
| `updated_at`   | `TIMESTAMP`                   |                                    |

**Index:** `pay_date`, `source`  
**Design note:** Each row is one pay instance ($2,500 bi-weekly = one row per payday). Dashboard shows most recent 20 entries (FILO). Batch entry allows adding multiple past pay dates in one session.

---

### 6.12 `fixed_expenses`

Recurring fixed expenses configuration (rent, insurance, subscriptions) — replaces the "Monthly Budget" sheet from the Excel workbook.

| Column         | Type                          | Notes                              |
|----------------|-------------------------------|------------------------------------|
| `id`           | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`      | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `category_id`  | `BIGINT UNSIGNED` FK → `categories.id` |                          |
| `name`         | `VARCHAR(255)`                | e.g. "Rent", "Tenant Insurance"    |
| `amount`       | `DECIMAL(10,2)`               | Amount per period (positive value) |
| `frequency`    | `ENUM('monthly','bi_weekly','weekly','quarterly','yearly')` |       |
| `due_day`      | `TINYINT UNSIGNED` NULLABLE   | Day of month (1–31), NULL if not fixed |
| `start_date`   | `DATE`                        | When this expense starts           |
| `end_date`     | `DATE` NULLABLE               | NULL = ongoing                     |
| `is_active`    | `BOOLEAN` DEFAULT `true`     |                                    |
| `sort_order`   | `INT` DEFAULT `0`            | Display ordering                   |
| `notes`        | `TEXT` NULLABLE               |                                    |
| `created_at`   | `TIMESTAMP`                   |                                    |
| `updated_at`   | `TIMESTAMP`                   |                                    |

**Index:** `category_id`, `is_active`

---

### 6.13 `envelope_pools`

Envelope budgeting — variable expense categories that accrue a monthly allowance. Money accumulates if unspent. Replaces the "Expense Pools" sheet from the Excel workbook.

| Column              | Type                          | Notes                              |
|---------------------|-------------------------------|------------------------------------|
| `id`                | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`           | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `category_id`       | `BIGINT UNSIGNED` FK → `categories.id` | Links to a category       |
| `name`              | `VARCHAR(255)`                | e.g. "Groceries Fund"             |
| `monthly_accrual`   | `DECIMAL(10,2)`               | Amount added per month             |
| `current_balance`   | `DECIMAL(12,2)` DEFAULT `0`  | Cached running balance             |
| `start_date`        | `DATE`                        | When accrual begins                |
| `is_active`         | `BOOLEAN` DEFAULT `true`     |                                    |
| `created_at`        | `TIMESTAMP`                   |                                    |
| `updated_at`        | `TIMESTAMP`                   |                                    |

**Index:** `category_id`, `is_active`  
**Balance calculation:** `current_balance = (months_since_start × monthly_accrual) - SUM(spending)`. Spending pulled from `transactions` matching the same `category_id`. Recalculated on import and on-demand.

---

### 6.14 `savings_milestones`

Long-term savings goals with milestone markers — replaces the milestone tracking from the Excel Statistics sheet.

| Column         | Type                          | Notes                              |
|----------------|-------------------------------|------------------------------------|
| `id`           | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`      | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `name`         | `VARCHAR(255)`                | e.g. "First $10K"                  |
| `target_amount`| `DECIMAL(12,2)`               | Milestone target                   |
| `reached_at`   | `DATETIME` NULLABLE           | When milestone was hit (auto-set)  |
| `is_reached`   | `BOOLEAN` DEFAULT `false`    | Quick lookup flag                  |
| `sort_order`   | `INT` DEFAULT `0`            |                                    |
| `created_at`   | `TIMESTAMP`                   |                                    |
| `updated_at`   | `TIMESTAMP`                   |                                    |

**Default milestones (seeded):**
$1K → Starter Emergency Fund, $5K → Short-Term Safety Net, $10K → Solid Foundation, $25K → Real Cushion, $50K → Major Milestone, $100K → Six Figures Club.

**Auto-detection:** After each import, `StatisticsEngine` checks total savings against unreached milestones and marks them with a timestamp.

---

### 6.15 `yearly_snapshots`

Cached year-over-year statistics — replaces the "Statistics" sheet and "Life Direction" indicator from the Excel workbook.

| Column              | Type                          | Notes                              |
|---------------------|-------------------------------|------------------------------------|
| `id`                | `BIGINT UNSIGNED` PK AUTO     |                                    |
| `user_id`           | `BIGINT UNSIGNED` FK → `users.id` |                                |
| `year`              | `SMALLINT UNSIGNED`           | Calendar year                      |
| `total_income`      | `DECIMAL(12,2)`               | Sum of all income_entries          |
| `total_expenses`    | `DECIMAL(12,2)`               | Sum of all negative transactions   |
| `total_fixed`       | `DECIMAL(12,2)`               | Sum of fixed expenses paid         |
| `total_variable`    | `DECIMAL(12,2)`               | Sum of variable/pool spending      |
| `net_savings`       | `DECIMAL(12,2)`               | income - expenses                  |
| `savings_rate`      | `DECIMAL(5,2)`                | (net_savings / total_income) × 100 |
| `expense_ratio`     | `DECIMAL(5,2)`                | (total_expenses / total_income) × 100 |
| `life_direction`    | `ENUM('improving','stable','declining')` | Based on YoY trend   |
| `metadata`          | `JSON` NULLABLE               | Breakdown by category, monthly detail |
| `calculated_at`     | `TIMESTAMP`                   | When this snapshot was last computed |
| `created_at`        | `TIMESTAMP`                   |                                    |
| `updated_at`        | `TIMESTAMP`                   |                                    |

**Unique constraint:** `year`  
**Life Direction logic:**
- **Improving (green):** savings_rate increased OR expense_ratio decreased vs. previous year
- **Stable (yellow):** within ±2% of previous year
- **Declining (red):** savings_rate decreased AND expense_ratio increased

---

## 7. Accrual System

Replaces the "Accrual Tracker" sheet from the Excel workbook. Computed in real-time by `AccrualCalculator.php` — no separate table needed.

**How it works:**
- Takes a `start_date` (e.g. move-in date) from `settings`
- For each active `fixed_expense`, prorates the amount daily from `start_date` to today
- Formula: `daily_rate = amount / days_in_month`, `accrued = daily_rate × days_elapsed`
- The Accrual page shows: what you owe right now (prorated), what you've actually paid, and the difference

**Example:**
Rent is $1,500/month, today is the 15th of a 30-day month:
- Daily rate: $1,500 / 30 = $50.00
- Accrued to date: $50.00 × 15 = $750.00
- If already paid: shows $0 remaining
- If not yet paid: shows $750.00 owing

This lets you see at any point in the month exactly how much of your fixed expenses you've "consumed" — essential for knowing your real available cash.

---

## 8. NBC CSV Parser

NBC (Banque Nationale) CSVs typically follow this format:

```csv
"Numéro de compte","Type de compte","Date de transaction","Description","Chèque #","Débit","Crédit","Solde"
"00001-12345","Opérations courantes","2025-01-15","PAIEMENT - IGA EXPRESS","","-42.50","","1,234.56"
```

**Parser mapping:**
| CSV Column             | → Transaction Field    | Transform                           |
|------------------------|------------------------|-------------------------------------|
| Date de transaction    | `transaction_date`     | Parse as `Y-m-d`, default time `00:00:00` |
| Description            | `description`          | Trim whitespace                     |
| Débit                  | `amount`               | Already negative (keep as-is)       |
| Crédit                 | `amount`               | Positive value                      |
| Solde                  | `balance_after`        | Strip comma separators, parse float |

**Note:** NBC uses separate Débit/Crédit columns, but we merge them into a single signed `amount` field. If Débit has a value, it's the amount (negative). If Crédit has a value, it's the amount (positive).

---

## 9. CSV Import Flow

```
Upload CSV → Select bank account + month/year
  → Validate file (size, extension, encoding)
  → Check if batch already exists for this account+month
      ├── If exists: prompt to replace or cancel
      └── If new: proceed
  → Detect bank format (default: NBC, extensible)
  → Parse rows using bank-specific parser
  → For each row:
      ├── Compute hash (epoch(datetime) + description + amount + balance_after)
      ├── Skip if hash exists in DB (from other months)
      ├── Run category rules (priority order, first match)
      └── Insert transaction
  → Create/update import_batch summary
  → Log to audit_logs
  → Return results (imported / skipped / duplicates)
```

---

## 10. Key Design Decisions

| Decision                     | Choice                    | Rationale                                |
|------------------------------|---------------------------|------------------------------------------|
| Amount storage               | Signed DECIMAL(12,2)      | Simple math: SUM(amount) = net balance   |
| Duplicate detection          | SHA-256 hash (epoch+desc+amount+balance) | Epoch-based hashing, reveals nothing about original values |
| Import structure             | Monthly batches           | One import per account per month, clean snapshots |
| Category hierarchy           | Adjacency list (parent_id)| Simple, 2 levels max, easy queries       |
| Auto-categorization          | Rule-based, priority order| Transparent, user-configurable           |
| Audit trail                  | Polymorphic log table     | Tracks all models, flexible JSON values  |
| Multi-tenancy              | user_id on all tables + global scope | Every query auto-scoped, impossible to leak data |
| Authentication             | Fortify + mandatory TOTP 2FA | Financial data requires strong auth |
| Encryption at rest         | `DB_ENCRYPT` env toggle   | Off in dev (inspect DB freely), on in prod (one command) |
| Email encryption           | Blind index (HMAC-SHA256) | Encrypted email + deterministic hash for login queries |
| Privacy                    | Loi 25 + PIPEDA compliant | Data minimization, env-toggled encryption, user rights built in |
| CSS framework                | Vuetify 3                 | Material Design, rich component library  |
| Routing                      | Inertia.js (server-side)  | No API layer needed, Laravel routes      |
| Income tracking              | Separate table, batch entry | Mirrors Excel Salary Log, FILO display |
| Fixed expenses               | Config table + accrual calc | Prorated daily, real-time owed amount  |
| Envelope budgeting           | Pool table + category link | Accrues monthly, debits from transactions |
| Statistics                   | Yearly snapshots + live calc | Cached for performance, recalc on import |
| Life Direction               | YoY savings_rate + expense_ratio | Green/yellow/red indicator        |
| Testing                      | Pest PHP                  | Clean syntax, parallel, Laravel-native   |
| Debugging                    | Xdebug 3 + Telescope     | Step debug via env toggle, web UI for queries |
| Code quality                 | Pint + PHPStan level 6   | Auto-format + static analysis            |
| DX automation                | Makefile + entrypoints    | One command startup, no manual steps     |

---

## 11. Future Considerations (Post-MVP)

- **Multi-currency:** Already have `currency` on bank_accounts
- **Recurring transactions:** Detection and prediction
- **Export:** PDF reports, CSV export
- **Bank API integration:** Open Banking APIs (if available in Canada)
- **Mobile:** PWA support via Vuetify's responsive components
- **Notifications:** Budget threshold alerts (email or push)
- **Admin panel:** User management, system health, global settings
- **OAuth / SSO:** Google, Apple sign-in as alternative to email+password
- **Passkeys (WebAuthn):** Hardware key / biometric as 2FA alternative
