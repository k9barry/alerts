#!/usr/bin/env bash
# Entrypoint for the Docker container. Uses Unix LF line endings to avoid \r issues when executed inside Linux-based containers.
set -euo pipefail

# Ensure we are in the app directory
cd /app

echo "Running database migrations..."
php scripts/migrate.php

echo "Starting scheduler..."
exec "$@"
