#!/bin/sh
set -e

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  BudgetApp — Node Container Starting"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

cd /var/www/html

# ──────────────────────────────────────────────
# Wait for package.json (Laravel project must exist)
# ──────────────────────────────────────────────
echo "⏳ Waiting for package.json..."
MAX_RETRIES=60
RETRY=0
until [ -f "package.json" ]; do
    RETRY=$((RETRY + 1))
    if [ $RETRY -ge $MAX_RETRIES ]; then
        echo "❌ package.json not found after ${MAX_RETRIES} attempts."
        echo "   Run 'make init' to create the Laravel project first."
        exit 1
    fi
    sleep 2
done
echo "✅ package.json found."

# ──────────────────────────────────────────────
# Install npm dependencies
# ──────────────────────────────────────────────
if [ ! -d "node_modules/.package-lock.json" ] || [ "package.json" -nt "node_modules/.package-lock.json" ]; then
    echo "📦 Running npm install..."
    npm install
else
    echo "📦 node_modules up to date, skipping npm install."
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  ✅ Starting Vite dev server on :5173"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# ──────────────────────────────────────────────
# Start Vite — exposed to all interfaces for Docker
# ──────────────────────────────────────────────
exec npm run dev -- --host 0.0.0.0
