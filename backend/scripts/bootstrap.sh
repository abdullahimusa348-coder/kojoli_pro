#!/usr/bin/env bash
# Nadabo Global Data: one-time foundation bootstrap.
# Installs the Laravel 12 skeleton UNDER the foundation files in this folder
# (foundation files always win), then installs tooling. Safe to read first.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

need() { command -v "$1" >/dev/null 2>&1 || { echo "Missing required tool: $1" >&2; exit 1; }; }
for t in php composer node npm rsync perl; do need "$t"; done

php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || { echo "PHP 8.3+ is required (found $(php -r 'echo PHP_VERSION;'))." >&2; exit 1; }

if [ -f artisan ]; then
  echo "artisan already exists: bootstrap has already run. Aborting." >&2
  exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "==> Creating Laravel 12 skeleton"
composer create-project "laravel/laravel:^12.0" "$TMP/app" --prefer-dist --no-interaction --no-scripts

echo "==> Merging skeleton beneath foundation files (existing files are kept)"
rsync -a --ignore-existing --exclude vendor --exclude node_modules --exclude .env "$TMP/app/" "$ROOT/"

echo "==> Registering routes/api.php in bootstrap/app.php"
if ! grep -q "routes/api.php" bootstrap/app.php; then
  perl -0pi -e "s|(web:\s*__DIR__\s*\.\s*'/\.\./routes/web\.php',)|\$1\n        api: __DIR__.'/../routes/api.php',|" bootstrap/app.php
fi
grep -q "routes/api.php" bootstrap/app.php \
  || { echo "Could not patch bootstrap/app.php. Add this line inside withRouting(): api: __DIR__.'/../routes/api.php'," >&2; exit 1; }

echo "==> Installing PHP dependencies"
composer install --no-interaction

[ -f .env ] || cp .env.example .env
php artisan key:generate --ansi

echo "==> Installing Pest"
composer require --dev pestphp/pest pestphp/pest-plugin-laravel -W --no-interaction
# Pest 3 has no artisan installer: bind Laravel's TestCase to Feature tests.
if [ ! -f tests/Pest.php ]; then
  printf '%s\n' '<?php' '' "pest()->extend(Tests\\TestCase::class)->in('Feature');" > tests/Pest.php
fi

echo "==> Installing frontend dependencies (Alpine.js, Vite, Tailwind)"
npm install
npm install alpinejs
npm run build

cat <<'NEXT'

Foundation installed. Remaining manual steps:
  1. Create an empty MySQL database and user.
  2. Put DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env (never commit .env).
  3. php artisan migrate
  4. php artisan test
  5. composer run dev   (or: php artisan serve  +  npm run dev)
  6. Check:  /up   and   /api/v1/health
NEXT
