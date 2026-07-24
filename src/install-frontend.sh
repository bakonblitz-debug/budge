#!/bin/bash
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
#  BudgetApp — Frontend Stack Installer
#  Run from project root: bash install-frontend.sh
# ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
set -e

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Installing Vue 3 + Inertia + Vuetify 3"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# ──────────────────────────────────────────────
# PHP: Install Inertia server-side adapter
# ──────────────────────────────────────────────
echo "📦 Installing Inertia Laravel adapter..."
docker exec budgetapp-app composer require inertiajs/inertia-laravel --no-interaction

# ──────────────────────────────────────────────
# Register Inertia middleware in bootstrap/app.php
# ──────────────────────────────────────────────
echo "🔧 Registering Inertia middleware..."
# Laravel 11 uses bootstrap/app.php for middleware
if ! grep -q "HandleInertiaRequests" src/bootstrap/app.php; then
    sed -i.bak "s|->withMiddleware(function (Middleware \$middleware) {|->withMiddleware(function (Middleware \$middleware) {\n        \$middleware->web(append: [\n            \\\\App\\\\Http\\\\Middleware\\\\HandleInertiaRequests::class,\n        ]);|" src/bootstrap/app.php
    rm -f src/bootstrap/app.php.bak
    echo "   ✅ Middleware registered"
else
    echo "   ⚠️  Middleware already registered, skipping"
fi

# ──────────────────────────────────────────────
# NPM: Install Vue, Inertia, Vuetify, and plugins
# ──────────────────────────────────────────────
echo "📦 Installing npm packages..."
docker exec budgetapp-node npm install \
    vue@^3 \
    @inertiajs/vue3 \
    @vitejs/plugin-vue \
    vuetify@^3 \
    vite-plugin-vuetify \
    @mdi/font \
    --save

# ──────────────────────────────────────────────
# Update docker-compose: pass HMR port to node container
# ──────────────────────────────────────────────
echo "🔧 Configuring Vite HMR port..."
if ! grep -q "VITE_HMR_PORT" docker-compose.yml; then
    sed -i.bak "s|VITE_PORT: 5173|VITE_PORT: 5173\n      VITE_HMR_PORT: \${VITE_PORT:-5174}|" docker-compose.yml
    rm -f docker-compose.yml.bak
    echo "   ✅ HMR port configured"
else
    echo "   ⚠️  Already configured, skipping"
fi

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  ✅ Frontend stack installed!"
echo ""
echo "  Restart containers to pick up changes:"
echo "    make down && make up"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
