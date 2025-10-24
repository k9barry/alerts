# Installation Guide

This guide will help you install and run the Weather Alerts System.

## Prerequisites

- Docker (version 20.10 or higher)
- Docker Compose (version 1.29 or higher)
- Git

## Installation Steps

### 1. Clone the Repository

```bash
git clone https://github.com/k9barry/alerts.git
cd alerts
```

### 2. Configure Environment Variables

Copy the example environment file:

```bash
cp .env.example .env
```

Edit the `.env` file with your preferred text editor:

```bash
nano .env
# or
vi .env
```

**Important**: Update the `CONTACT_EMAIL` variable with your email address. This is required by the weather.gov API.

```env
CONTACT_EMAIL=your-email@example.com
```

### 3. Build and Start Services

Build the Docker images and start all services:

```bash
docker-compose up -d
```

This command will:
- Build the alerts application image
- Pull required images (SQLite Browser, Dozzle)
- Create necessary volumes
- Start all services in detached mode

### 4. Verify Installation

Check that all services are running:

```bash
docker-compose ps
```

You should see three services running:
- `alerts-app`
- `alerts-sqlitebrowser`
- `alerts-dozzle`

### 5. View Logs

To view the application logs:

```bash
docker-compose logs -f alerts
```

Or use the Dozzle web interface at http://localhost:8080

## Accessing Services

### Dozzle (Log Viewer)

Open your web browser and navigate to:
```
http://localhost:8080
```

Dozzle provides real-time log streaming for all Docker containers.

### SQLite Browser

Open your web browser and navigate to:
```
http://localhost:3000
```

To view the database:
1. Click "Open Database"
2. Navigate to `/config/alerts.db`
3. Browse tables and run queries

## Stopping Services

To stop all services:

```bash
docker-compose down
```

To stop and remove all data (including database):

```bash
docker-compose down -v
```

⚠️ **Warning**: The `-v` flag will delete all stored alerts!

## Updating

To update to the latest version:

```bash
git pull
docker-compose down
docker-compose up -d --build
```

## Troubleshooting

### Service Won't Start

Check the logs for errors:
```bash
docker-compose logs alerts
```

### Permission Issues

If you encounter permission issues with the database or logs:

```bash
chmod -R 755 data/ logs/
```

### Database Lock Errors

If the database is locked:
1. Stop all services: `docker-compose down`
2. Wait a few seconds
3. Restart: `docker-compose up -d`

### API Rate Limit Errors

If you see rate limit errors in the logs:
- The application automatically handles rate limiting
- Wait for the rate limit window to expire (60 seconds)
- The application will retry automatically

### No Alerts Being Fetched

Check:
1. Your internet connection
2. The weather.gov API status
3. Your contact email is set in `.env`
4. Logs for specific error messages

## Manual Database Inspection

To inspect the database manually:

```bash
# Access the container
docker exec -it alerts-app sh

# Open SQLite
sqlite3 /app/data/alerts.db

# Run queries
SELECT COUNT(*) FROM alerts;
SELECT event, severity, headline FROM alerts LIMIT 10;
.quit
```

## Running Manually

To run the application manually (outside of Docker):

### Requirements
- PHP 8.1 or higher
- SQLite extension
- cURL extension
- Composer

### Steps

```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Edit with your email
nano .env

# Run the application
php src/app.php
```

## Customization

### Changing Update Frequency

Edit `docker-compose.yml` and modify the sleep interval in the alerts service command:

```yaml
command: sh -c "while true; do php src/app.php; sleep 300; done"
#                                                        ^^^ seconds
```

### Changing Port Numbers

Edit `docker-compose.yml` and modify the port mappings:

```yaml
ports:
  - "3000:3000"  # SQLite Browser (change first number)
  - "8080:8080"  # Dozzle (change first number)
```

## Production Deployment

For production deployment:

1. Set `APP_ENV=production` in `.env`
2. Set `LOG_LEVEL=INFO` or `LOG_LEVEL=WARNING`
3. Use Docker secrets for sensitive data
4. Set up proper backup procedures for the database
5. Configure log rotation
6. Set up monitoring and alerting
7. Use a reverse proxy (nginx/traefik) for HTTPS

## Backup and Restore

### Backup

```bash
# Backup database
cp data/alerts.db backups/alerts-$(date +%Y%m%d).db

# Or using docker
docker exec alerts-app cp /app/data/alerts.db /app/data/backup.db
docker cp alerts-app:/app/data/backup.db ./backups/
```

### Restore

```bash
# Stop services
docker-compose down

# Restore database
cp backups/alerts-YYYYMMDD.db data/alerts.db

# Start services
docker-compose up -d
```

## Support

If you encounter issues not covered in this guide, please:
1. Check the logs in Dozzle
2. Review the application logs: `docker-compose logs alerts`
3. Open an issue on GitHub with:
   - Your Docker and Docker Compose versions
   - Relevant log excerpts
   - Steps to reproduce the issue
