# check_zones_data.php

Automated zone data download script that runs during container startup.

## Overview

This script checks if the NWS weather zones data file exists and downloads it automatically if not present. It's designed to be run non-interactively during Docker container initialization, before database migrations.

## Purpose

Ensures the zones data file is available before `migrate.php` attempts to load it into the database, eliminating the need for manual intervention when starting the application for the first time.

## Location

`scripts/check_zones_data.php`

## Execution

### Docker Container Startup

Automatically runs via `docker/entrypoint.sh`:

```bash
php scripts/check_zones_data.php
```

### Manual Execution

Can also be run manually:

```bash
php scripts/check_zones_data.php
```

## Behavior

### If zones file exists:
- Reports file path and size
- Exits successfully (code 0)
- No download attempted

### If zones file does not exist:
- Attempts to download from `ZONES_DATA_URL` config
- Default URL: `https://www.weather.gov/source/gis/Shapefiles/County/bp18mr25.dbx`
- Saves to data directory (e.g., `data/bp18mr25.dbx`)
- Reports download progress and success

### On download failure:
- Logs error message to STDERR
- Exits successfully (code 0) anyway - doesn't block container startup
- User can download manually later with `scripts/download_zones.php`

## Configuration

Uses configuration from `App\Config`:

- **`Config::$dbPath`** - Determines data directory location
- **`Config::$zonesDataUrl`** - URL to download zones data from

Set via environment variables:
- `DB_PATH` (default: `/data/alerts.sqlite`)
- `ZONES_DATA_URL` (default: NWS shapefile URL)

## Exit Codes

- **0**: Success (file exists, downloaded successfully, or failed but non-critical)
- Never exits with failure code to avoid blocking container startup

## Example Output

### File exists:
```
Zones data file exists: /data/bp18mr25.dbx (125437 bytes)
```

### Downloading:
```
Zones data file not found. Downloading from NWS...
URL: https://www.weather.gov/source/gis/Shapefiles/County/bp18mr25.dbx
Successfully downloaded zones data (125437 bytes)
Saved to: /data/bp18mr25.dbx
```

### Download failure (non-blocking):
```
Zones data file not found. Downloading from NWS...
URL: https://www.weather.gov/source/gis/Shapefiles/County/bp18mr25.dbx
Failed to download zones data.
HTTP Code: 404
Warning: Continuing without zones data. You can download it later with: php scripts/download_zones.php
```

## Related

- [`migrate.php`](./migrate.md) - Runs after this script, loads zones data into database
- [`download_zones.php`](../../scripts/download_zones.php) - Interactive manual download script (source file)
- [Zones Data Documentation](../zones-data.md) - File format and structure

## Error Handling

- Creates data directory if it doesn't exist
- Handles cURL errors gracefully
- Non-blocking: Always exits successfully to allow container to start
- Logs warnings to STDERR for visibility in container logs

## Network Requirements

- Outbound HTTPS access to `weather.gov` domain
- Requires internet connectivity
- Uses cURL with 120-second timeout
- Follows HTTP redirects
