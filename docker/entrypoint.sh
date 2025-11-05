#!/usr/bin/env bash
# Entrypoint for the Docker container. Uses Unix LF line endings to avoid \r issues when executed inside Linux-based containers.
set -euo pipefail

# Ensure we are in the app directory
cd /app

# Check if zones data needs to be downloaded
echo "Checking zones data..."
php scripts/check_zones_data.php

echo "Running database migrations..."
php scripts/migrate.php

echo "Starting scheduler..."
exec "$@"
