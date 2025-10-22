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


set_env_value() {
  local file="$1"
  local key="$2"
  local value="$3"
  if [ ! -f "$file" ]; then
    return
  fi
  python3 - "$file" "$key" "$value" <<'PYEOF'
import sys
from pathlib import Path
file = Path(sys.argv[1])
key = sys.argv[2]
value = sys.argv[3]
lines = file.read_text().splitlines()
found = False
for i, line in enumerate(lines):
    if line.startswith(key + "="):
        lines[i] = f"{key}={value}"
        found = True
        break
if not found:
    lines.append(f"{key}={value}")
file.write_text("\n".join(lines) + "\n")
PYEOF
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

  if ! command -v composer >/dev/null 2>&1; then
    if [ "$manager" = "brew" ]; then
      install_packages "$manager" composer
    elif [ "$manager" = "apt" ]; then
      install_packages "$manager" composer
    else
      echo "Composer is required. Please install Composer manually." >&2
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

# Ensure SQLite database exists
SQLITE_DB="$ROOT_DIR/database/database.sqlite"
mkdir -p "$(dirname "$SQLITE_DB")"
if [ ! -f "$SQLITE_DB" ]; then
  echo "Creating SQLite database at $SQLITE_DB"
  touch "$SQLITE_DB"
fi

# ---------------------------------------------------------------------------
# Composer (composer.phar)
# ---------------------------------------------------------------------------

echo "Installing PHP dependencies (composer install)..."
composer install --no-interaction --prefer-dist

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

"$VENV_DIR/bin/pip" install --upgrade pip >/dev/null 2>&1 || true
if [ -f "$ROOT_DIR/python-client/requirements.txt" ]; then
  echo "Installing Python dependencies (python-client/requirements.txt)..."
  "$VENV_DIR/bin/pip" install -r "$ROOT_DIR/python-client/requirements.txt"
else
  echo "Warning: python-client/requirements.txt not found, skipping Python dependency installation." >&2
fi

echo "Ensuring main scripts are executable..."
chmod +x "$ROOT_DIR/run.sh" "$ROOT_DIR/scripts/install.sh"

PYTHON_VALUE=".venv/bin/python3"
if [[ "$OS" == "Windows_NT" || "$OSTYPE" == msys* || "$OSTYPE" == cygwin* ]]; then
  PYTHON_VALUE=".venv\\Scripts\\python.exe"
fi
set_env_value "$ROOT_DIR/.env" "PYTHON_BINARY" "$PYTHON_VALUE"
set_env_value "$ROOT_DIR/.env.example" "PYTHON_BINARY" "$PYTHON_VALUE"

echo "\nBootstrap completed. Next step:"
echo "  php artisan app:install"
