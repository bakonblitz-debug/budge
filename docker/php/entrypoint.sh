#!/bin/bash
set -e

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  BudgetApp — PHP Container Starting"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

cd /var/www/html

# ──────────────────────────────────────────────
# Wait for MySQL to be ready (belt + suspenders with healthcheck)
# ──────────────────────────────────────────────
echo "⏳ Waiting for MySQL..."
MAX_RETRIES=30
RETRY=0
until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    RETRY=$((RETRY + 1))
    if [ $RETRY -ge $MAX_RETRIES ]; then
        echo "❌ MySQL not reachable after ${MAX_RETRIES} attempts. Exiting."
        exit 1
    fi
    echo "   Attempt ${RETRY}/${MAX_RETRIES}..."
    sleep 2
done
echo "✅ MySQL is ready."

# ──────────────────────────────────────────────
# Composer install (skip if vendor exists and lock hasn't changed)
# ──────────────────────────────────────────────
if [ ! -f "vendor/autoload.php" ] || [ "composer.lock" -nt "vendor/autoload.php" ]; then
    echo "📦 Running composer install..."
    composer install --no-interaction --optimize-autoloader --no-progress
else
    echo "📦 Vendor up to date, skipping composer install."
fi

# ──────────────────────────────────────────────
# Laravel bootstrap
# ──────────────────────────────────────────────
if [ -f "artisan" ]; then
    # Generate app key if not set
    if [ -z "$(grep '^APP_KEY=base64:' .env 2>/dev/null)" ]; then
        echo "🔑 Generating application key..."
        php artisan key:generate --force
    fi

    # Clear and cache config
    echo "🔧 Caching configuration..."
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear

    # Run migrations
    echo "🗃️  Running migrations..."
    php artisan migrate --force

    # Seed if database is empty (first run)
    if [ "$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null)" = "0" ] 2>/dev/null; then
        echo "🌱 Seeding database..."
        php artisan db:seed --force
    fi

    # Create storage link if not exists
    if [ ! -L "public/storage" ]; then
        echo "🔗 Creating storage symlink..."
        php artisan storage:link
    fi

    # Fix permissions
    echo "🔒 Setting permissions..."
    chown -R www-data:www-data storage bootstrap/cache
    chmod -R 775 storage bootstrap/cache
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  ✅ BudgetApp ready — Xdebug: ${XDEBUG_MODE:-off}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Hand off to CMD (php-fpm)
exec "$@"
