# Installation Guide

This guide provides detailed instructions for installing and configuring the Alerts application.

## System Requirements

- **Docker**: Version 20.10 or higher
- **Docker Compose**: Version 2.0 or higher
- **Internet Access**: Required for:
  - Pulling Docker images
  - Accessing weather.gov API
  - Sending notifications (Pushover/ntfy)

## Installation Steps

### 1. Clone the Repository

```sh
git clone https://github.com/k9barry/alerts.git
cd alerts
```

### 2. Configure Environment

Copy the example environment file:
```sh
cp .env.example .env
```

Edit `.env` with your preferred text editor and configure the following settings:

#### Application Settings
```env
APP_NAME="alerts"
APP_VERSION="0.1.0"
APP_CONTACT_EMAIL="you@example.com"  # Used in User-Agent header for API requests
```

#### Polling and Rate Limits
```env
POLL_MINUTES="3"              # How often to check for new alerts
API_RATE_PER_MINUTE="4"       # Weather.gov API rate limit
PUSHOVER_RATE_SECONDS="2"     # Delay between Pushover notifications
VACUUM_HOURS="24"             # Database VACUUM interval
```

#### Database
```env
DB_PATH="/data/alerts.sqlite"  # Path inside container (mounted to ./data)
```

#### Logging
```env
LOG_CHANNEL="stdout"    # stdout or file
LOG_LEVEL="info"        # debug, info, notice, warning, error, critical, alert, emergency
```

#### Weather API and Timezone
```env
WEATHER_API_URL="https://api.weather.gov/alerts/active"
TIMEZONE="America/Indianapolis"  # IANA timezone for timestamp localization
```

**Note**: Alert filtering is configured per-user through the web UI (http://localhost:8080) by selecting specific weather zones, not through environment variables.

#### Notification Channels

**Pushover** (enabled by default):
```env
PUSHOVER_ENABLED="true"
PUSHOVER_API_URL="https://api.pushover.net/1/messages.json"
PUSHOVER_USER="your_user_key"    # Required: Get from pushover.net
PUSHOVER_TOKEN="your_app_token"  # Required: Get from pushover.net
```

**ntfy** (disabled by default):
```env
NTFY_ENABLED="false"
NTFY_BASE_URL="https://ntfy.sh"
NTFY_TOPIC="your_topic_name"     # Required if enabled
NTFY_TOKEN=""                    # Optional: Bearer token
NTFY_USER=""                     # Optional: Basic auth username
NTFY_PASSWORD=""                 # Optional: Basic auth password
NTFY_TITLE_PREFIX=""             # Optional: Prefix for notification titles
```

### 3. Optional: Configure SSL Certificates

If you encounter SSL certificate errors (cURL error 60), download and configure a CA bundle:

```sh
# Create certs directory
mkdir -p certs

# Download Mozilla's CA bundle
curl -o certs/cacert.pem https://curl.se/ca/cacert.pem

# Uncomment these lines in .env
SSL_CERT_FILE=certs/cacert.pem
CURL_CA_BUNDLE=certs/cacert.pem
```

### 4. Configure Git (Recommended)

Set Git to use LF line endings to prevent issues with shell scripts:

```sh
git config core.autocrlf input
```

### 5. Build and Start

Build the Docker images and start all services:

```sh
docker compose up --build -d
```

This command will:
- Build the PHP application container
- Pull required Docker images (sqlitebrowser, dozzle)
- Run database migrations automatically
- Start the scheduler in the background

### 6. Verify Installation

Check that all containers are running:
```sh
docker compose ps
```

You should see three containers running:
- `alerts` - Main application
- `sqlitebrowser` - Database viewer
- `dozzle` - Log viewer

### 7. Access Services

- **Dozzle (Logs)**: http://localhost:9999
  - View real-time JSON-formatted logs
  - Filter by container, log level, or search text
  
- **SQLite Browser**: http://localhost:3000
  - Browse database tables
  - Execute SQL queries
  - Export data

- **Application**: http://localhost:8080
  - Currently returns 404 (GUI not implemented)

## Data Locations

All persistent data is stored in the `./data` directory:

- **./data/alerts.sqlite** - SQLite database file
- **./data/*.sqlite-wal** - Write-Ahead Log files
- **./data/*.sqlite-shm** - Shared memory files

If using file-based logging (`LOG_CHANNEL=file`):
- **./logs/app.log** - Application log file

## Upgrading

To upgrade to the latest version:

```sh
# Pull latest code
git pull origin main

# Rebuild containers
docker compose down
docker compose up --build -d
```

Database migrations run automatically on container start.

## Running Without Docker

### Prerequisites
- PHP 8.1 or higher
- PHP extensions: pdo, pdo_sqlite
- Composer

### Steps

1. Install dependencies:
```sh
composer install
```

2. Create required directories:
```sh
mkdir -p data logs
```

3. Configure environment:
```sh
cp .env.example .env
# Edit .env and set DB_PATH=data/alerts.sqlite
```

4. Run migrations:
```sh
php scripts/migrate.php
```

5. Start the scheduler:
```sh
php scripts/scheduler.php
```

## Troubleshooting

### Port Conflicts

If ports 8080, 3000, or 9999 are already in use, edit `docker-compose.yml` to change the port mappings:

```yaml
ports:
  - "YOUR_PORT:8080"  # Change YOUR_PORT to an available port
```

### Permission Issues

If you encounter permission errors with the `data` directory:

```sh
# Ensure the directory is writable
chmod -R 775 data
```

### SSL Certificate Errors

If you see `cURL error 60: SSL certificate problem`:

1. Follow the SSL certificate configuration in step 3 above
2. Or disable SSL verification (not recommended for production):
   ```env
   # In your local environment only
   VERIFY_SSL=false
   ```

### Line Ending Issues

If you see errors like `env: 'bash\r': No such file or directory`:

**On Windows:**
```sh
git config --global core.autocrlf input
git checkout -f HEAD
```

**Fix existing files:**
```sh
dos2unix docker/entrypoint.sh
# Or using sed:
sed -i 's/\r$//' docker/entrypoint.sh
```

### Database Locked Errors

If you see "database is locked" errors:

1. Ensure only one instance of the scheduler is running
2. Check that no other processes are accessing the database
3. Restart the container:
   ```sh
   docker compose restart alerts
   ```

### No Alerts Received

1. **Check logs**: View Dozzle at http://localhost:9999
2. **Verify API access**: Ensure you can reach https://api.weather.gov/alerts/active
3. **Check user zone configuration**: Ensure users have selected weather zones in the web UI at http://localhost:8080
4. **Verify notification credentials**: Check Pushover/ntfy credentials are correct in the user settings
5. **Check active alerts**: Visit https://alerts.weather.gov to see if there are active alerts in your area matching your configured zones

### View Database Contents

Use SQLite Browser at http://localhost:3000 to:
- View `incoming_alerts` table for latest API data
- Check `pending_alerts` for queued notifications
- Review `sent_alerts` for notification history
- Examine `active_alerts` for currently tracked alerts

## Uninstallation

To completely remove the application:

```sh
# Stop and remove containers
docker compose down

# Remove images (optional)
docker compose down --rmi all

# Remove data (optional - this deletes your database!)
rm -rf data logs

# Remove the repository
cd ..
rm -rf alerts
```

## Next Steps

- See [README.DEV.md](README.DEV.md) for development setup
- See [documentation/](documentation/) for detailed component documentation
- Configure user weather zones through the web UI at http://localhost:8080
- Set up multiple notification channels for redundancy
