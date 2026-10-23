#!/bin/bash

set -e

if [ ! -f .env ]; then
    echo "[start] .env not found — copying from .env.example"
    cp .env.example .env
fi

# Load .env (strip CRLF for Windows compatibility)
set -a
# shellcheck source=.env
source <(sed 's/\r//' .env)
set +a

docker compose up -d

if docker compose config --services 2>/dev/null | grep -q "^db$"; then
    echo "[start] waiting for MySQL to be ready..."
    until docker compose exec db mysql -u root -p"${DB_PASSWORD:-root}" -e "SELECT 1;" >/dev/null 2>&1; do
        sleep 1
    done
    echo "[start] MySQL is ready."
fi

docker compose exec app bash
