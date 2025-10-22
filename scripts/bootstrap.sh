#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT_DIR"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required to bootstrap the project." >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is required to bootstrap the project." >&2
  exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "npm is required to bootstrap the project." >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Composer (via composer.phar)
# ---------------------------------------------------------------------------

if [ ! -f composer.phar ]; then
  echo "Downloading composer.phar..."
  EXPECTED_SIGNATURE=$(php -r "copy('https://composer.github.io/installer.sig', 'php://stdout');")
  php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
  ACTUAL_SIGNATURE=$(php -r "echo hash_file('sha384', 'composer-setup.php');")
  if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
    echo 'ERROR: Invalid composer installer signature' >&2
    rm composer-setup.php
    exit 1
  fi
  php composer-setup.php --quiet
  rm composer-setup.php
fi

echo "Installing PHP dependencies (composer.phar install)..."
php composer.phar install --no-interaction --prefer-dist

# ---------------------------------------------------------------------------
# Node dependencies
# ---------------------------------------------------------------------------

echo "Installing Node.js dependencies (npm install)..."
npm install

# ---------------------------------------------------------------------------
# Python virtual environment + requirements
# ---------------------------------------------------------------------------

VENV_DIR="$ROOT_DIR/.venv"
if [ ! -d "$VENV_DIR" ]; then
  echo "Creating Python virtual environment (.venv)..."
  python3 -m venv "$VENV_DIR"
fi

echo "Installing Python dependencies (python-client requirements)..."
"$VENV_DIR/bin/pip" install --upgrade pip >/dev/null 2>&1 || true
"$VENV_DIR/bin/pip" install -r "$ROOT_DIR/python-client/requirements.txt"

echo "\nBootstrap completed. Next steps:"
echo "  1) cp .env.example .env (if not already done)"
echo "  2) php artisan app:install"
