#!/usr/bin/env bash
set -euo pipefail

# Ensure we are in the app directory
cd /app

echo "Running database migrations..."
php scripts/migrate.php

echo "Starting scheduler..."
exec "$@"
