#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT_DIR"

detect_package_manager() {
  if command -v brew >/dev/null 2>&1; then
    echo "brew"
  elif command -v apt-get >/dev/null 2>&1; then
    echo "apt"
  else
    echo "none"
  fi
}

install_packages() {
  local manager="$1"; shift
  local packages=("$@")

  if [ "$manager" = "brew" ]; then
    for pkg in "${packages[@]}"; do
      if ! brew list "$pkg" >/dev/null 2>&1; then
        echo "Installing $pkg (Homebrew)"
        brew install "$pkg"
      fi
    done
  elif [ "$manager" = "apt" ]; then
    echo "Installing ${packages[*]} (apt-get – may require sudo password)"
    sudo apt-get update
    sudo apt-get install -y "${packages[@]}"
  else
    echo "Warning: unsupported package manager. Please install ${packages[*]} manually." >&2
  fi
}

ensure_runtime_tooling() {
  local manager
  manager=$(detect_package_manager)

  if ! command -v php >/dev/null 2>&1; then
    if [ "$manager" = "brew" ]; then
      install_packages "$manager" php
    elif [ "$manager" = "apt" ]; then
      install_packages "$manager" php php-cli php-mbstring php-xml php-curl php-zip php-sqlite3
    else
      echo "PHP is required to bootstrap the project." >&2
      exit 1
    fi
  fi

  if ! command -v python3 >/dev/null 2>&1; then
    if [ "$manager" = "brew" ]; then
      install_packages "$manager" python
    elif [ "$manager" = "apt" ]; then
      install_packages "$manager" python3 python3-venv python3-pip
    else
      echo "python3 is required to bootstrap the project." >&2
      exit 1
    fi
  fi

  if ! command -v npm >/dev/null 2>&1; then
    if [ "$manager" = "brew" ]; then
      install_packages "$manager" node
    elif [ "$manager" = "apt" ]; then
      install_packages "$manager" nodejs npm
    else
      echo "npm (Node.js) is required to bootstrap the project." >&2
      exit 1
    fi
  fi

  if ! command -v ffmpeg >/dev/null 2>&1; then
    if [ "$manager" = "brew" ]; then
      install_packages "$manager" ffmpeg
    elif [ "$manager" = "apt" ]; then
      install_packages "$manager" ffmpeg
    else
      echo "Warning: ffmpeg not found. Install it manually to enable audio processing." >&2
    fi
  fi
}

ensure_runtime_tooling

# ---------------------------------------------------------------------------
# Composer (composer.phar)
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
