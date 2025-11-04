# Quick Deployment Guide

This is a quick reference for deploying the Alerts application with Traefik integration.

## Prerequisites Checklist

- [ ] Docker and Docker Compose installed
- [ ] Traefik container running
- [ ] External network `traefik` exists
- [ ] DNS configured for `noaa.jafcp.com`
- [ ] Dozzle container running (optional, for logs)

## Quick Start

### 1. Verify Traefik Network

```bash
docker network ls | grep traefik
```

If not found, create it:
```bash
docker network create traefik
```

### 2. Clone and Configure

```bash
git clone https://github.com/k9barry/alerts.git
cd alerts
cp .env.example .env
nano .env  # Edit configuration
```

**Required Configuration:**
```env
APP_CONTACT_EMAIL="your-email@example.com"
TIMEZONE="America/Indiana/Indianapolis"
```

**For Pushover notifications:**
```env
PUSHOVER_ENABLED="true"
# User-specific credentials configured via web UI
```

**For ntfy notifications:**
```env
NTFY_ENABLED="true"
# User-specific credentials configured via web UI
```

### 3. Deploy

```bash
docker compose up -d
```

### 4. Verify

**Check containers:**
```bash
docker compose ps
```

**Check logs:**
```bash
docker logs alerts
docker logs alerts_scheduler
```

**Or use Dozzle:**
Navigate to `https://dozzle.jafcp.com`

**Access application:**
Navigate to `https://noaa.jafcp.com`

## Service Overview

| Service | Container Name | Purpose | Access |
|---------|---------------|---------|--------|
| alerts | `alerts` | Web interface for user management | https://noaa.jafcp.com |
| scheduler | `alerts_scheduler` | Background polling and alert processing | Logs only |
| sqlitebrowser | `sqlitebrowser` | Database browser (internal) | Not exposed |

## Network Configuration

- **Network**: `traefik` (external)
- **Domain**: `noaa.jafcp.com`
- **Protocol**: HTTPS via Traefik
- **Authentication**: Basic Auth (configured in Traefik labels)
- **Internal Ports**: 8080 (alerts), 3000 (sqlitebrowser)

## Traefik Labels Summary

```yaml
traefik.enable=true
traefik.http.routers.alerts.entrypoints=websecure
traefik.http.routers.alerts.rule=Host(`noaa.jafcp.com`)
traefik.http.routers.alerts.middlewares=simpleAuth
traefik.http.services.alerts.loadbalancer.server.port=8080
```

## Common Commands

**View logs:**
```bash
docker compose logs -f
docker compose logs -f scheduler  # Scheduler only
```

**Restart services:**
```bash
docker compose restart
docker compose restart alerts      # Single service
```

**Stop services:**
```bash
docker compose down
```

**Rebuild and restart:**
```bash
docker compose down
docker compose up --build -d
```

**Execute commands in container:**
```bash
docker exec -it alerts bash
docker exec alerts php scripts/download_zones.php
```

## Verification Steps

1. **Container Status:**
   ```bash
   docker compose ps
   ```
   All services should show "Up" status.

2. **Traefik Discovery:**
   ```bash
   docker logs traefik | grep alerts
   ```
   Should show configuration received for alerts router.

3. **Network Connectivity:**
   ```bash
   docker network inspect traefik | grep alerts
   ```
   Should list the alerts container.

4. **Application Response:**
   ```bash
   curl -I https://noaa.jafcp.com
   ```
   Should return 200 or 401 (auth required).

5. **Scheduler Activity:**
   ```bash
   docker logs alerts_scheduler --tail 50
   ```
   Should show polling activity every 3 minutes (default).

## Troubleshooting Quick Reference

### Service not accessible via domain

1. Check Traefik can see the service:
   ```bash
   docker logs traefik | grep alerts
   ```

2. Verify network:
   ```bash
   docker network inspect traefik
   ```

3. Check DNS:
   ```bash
   nslookup noaa.jafcp.com
   ```

### Database locked errors

```bash
docker compose restart
```

### Container won't start

```bash
docker compose logs alerts
docker compose logs scheduler
```

### No alerts being processed

1. Check scheduler logs:
   ```bash
   docker logs alerts_scheduler -f
   ```

2. Verify API connectivity:
   ```bash
   docker exec alerts curl -I https://api.weather.gov/alerts/active
   ```

3. Check configuration:
   ```bash
   docker exec alerts cat /data/alerts.sqlite
   ls -lh data/
   ```

## File Structure

```
alerts/
├── docker-compose.yml       # Main configuration
├── .env                     # Environment variables (create from .env.example)
├── data/                    # SQLite database (auto-created)
│   └── alerts.sqlite
├── logs/                    # Application logs (if LOG_CHANNEL=file)
├── TRAEFIK.md              # Detailed Traefik guide
├── INSTALL.md              # Standalone installation
└── README.md               # Full documentation
```

## Data Backup

**Backup database:**
```bash
cp -a data data.backup.$(date +%Y%m%d)
```

**Restore database:**
```bash
docker compose down
cp -a data.backup.YYYYMMDD/* data/
docker compose up -d
```

## Updating

```bash
cd alerts
git pull
docker compose down
docker compose up --build -d
```

Database migrations run automatically on startup.

## Security Notes

1. **Credentials in .env**: Never commit `.env` to git
2. **Basic Auth**: Configured via Traefik labels (hashed password)
3. **Database**: Stored in `./data/` directory
4. **Logs**: May contain sensitive data if LOG_LEVEL=debug
5. **Network**: Services can communicate on traefik network

## Next Steps

- Configure user accounts via web interface at https://noaa.jafcp.com
- Subscribe users to weather zones
- Set up notification preferences (Pushover/ntfy credentials per user)
- Monitor logs in Dozzle for alert processing
- Optionally download weather zones data:
  ```bash
  docker exec alerts php scripts/download_zones.php
  ```

## Resources

- **Full Documentation**: [README.md](./README.md)
- **Traefik Integration**: [TRAEFIK.md](./TRAEFIK.md)
- **Standalone Installation**: [INSTALL.md](./INSTALL.md)
- **Architecture**: [documentation/INDEX.md](./documentation/INDEX.md)

## Support

For issues:
1. Check logs: `docker compose logs`
2. Review [TRAEFIK.md](./TRAEFIK.md) troubleshooting section
3. Open issue on GitHub: https://github.com/k9barry/alerts/issues
