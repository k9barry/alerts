# Traefik Integration Guide

This guide explains how to integrate the Alerts application with an existing Traefik and Dozzle setup.

## Overview

The Alerts application can be integrated into an existing Docker environment that uses:
- **Traefik** as a reverse proxy for SSL termination and routing
- **Dozzle** as a centralized log viewer

This configuration allows you to access the Alerts web interface via a custom domain (e.g., `noaa.jafcp.com`) without exposing ports directly.

## Prerequisites

1. **Existing Traefik Container**: A running Traefik instance configured with:
   - SSL/TLS support (Let's Encrypt or custom certificates)
   - WebSecure entrypoint (port 443)
   - Access to Docker socket for service discovery

2. **External Network**: A Docker network named `traefik` that Traefik uses:
   ```bash
   docker network create traefik
   ```

3. **Dozzle Container** (Optional): A running Dozzle instance for log viewing

## Configuration

The `docker-compose.yml` has been configured to:

### 1. Use External Traefik Network

```yaml
networks:
  traefik:
    external: true
```

This connects the Alerts services to your existing `traefik` network, allowing Traefik to discover and route traffic to them.

### 2. Remove Internal Dozzle

The internal Dozzle container has been removed since you already have a centralized Dozzle instance in your main stack. View Alerts logs through your existing Dozzle at `dozzle.jafcp.com`.

### 3. Traefik Labels on Alerts Service

```yaml
labels:
  - traefik.enable=true
  - traefik.http.routers.alerts.entrypoints=websecure
  - traefik.http.routers.alerts.rule=Host(`noaa.jafcp.com`)
  - traefik.http.routers.alerts.middlewares=simpleAuth
  - traefik.http.middlewares.simpleAuth.basicauth.users=k9barry:$$apr1$$Gvf0.vYy$$8WOCVhhCLhtIvz5wqktX20
  - traefik.http.services.alerts.loadbalancer.server.scheme=http
  - traefik.http.services.alerts.loadbalancer.server.port=8080
  - diun.enable=true
```

**Key Labels Explained:**
- `traefik.enable=true`: Enables Traefik routing for this container
- `traefik.http.routers.alerts.entrypoints=websecure`: Uses HTTPS (port 443)
- `traefik.http.routers.alerts.rule=Host(\`noaa.jafcp.com\`)`: Routes requests for this domain
- `traefik.http.routers.alerts.middlewares=simpleAuth`: Applies basic authentication
- `traefik.http.services.alerts.loadbalancer.server.port=8080`: Internal container port
- `diun.enable=true`: Enables Docker Update Notifier integration

### 4. No Direct Port Exposure

The `ports:` sections have been removed from the services since access is now handled through Traefik. This improves security by not exposing services directly on the host.

## Services

### alerts
- **URL**: `https://noaa.jafcp.com`
- **Purpose**: Web interface for managing users and viewing the application
- **Authentication**: Basic Auth (configured in Traefik labels)
- **Port**: Internal 8080 (routed by Traefik)

### sqlitebrowser
- **Purpose**: Web-based SQLite database browser
- **Access**: Internal only (no Traefik routing configured)
- **Port**: Internal 3000
- **Note**: To access, add Traefik labels similar to the alerts service or use `docker exec`

### scheduler
- **Purpose**: Background service that polls weather.gov and processes alerts
- **Access**: No web interface (background only)
- **Logs**: View in Dozzle

## Deployment

### 1. Ensure Traefik Network Exists

```bash
docker network create traefik
```

### 2. Configure Environment

Copy and edit the environment file:
```bash
cp .env.example .env
nano .env
```

Set the required variables (see `.env.example` for all options):
- `APP_CONTACT_EMAIL`: Your contact email
- `PUSHOVER_USER` and `PUSHOVER_TOKEN` (if using Pushover)
- `NTFY_TOPIC` (if using ntfy)
- `WEATHER_ALERT_CODES` (optional geographic filtering)
- `TIMEZONE` (your IANA timezone)

### 3. Start the Services

```bash
docker compose up -d
```

### 4. Verify Services

Check that containers are running:
```bash
docker compose ps
```

Check logs in Dozzle:
- Navigate to `https://dozzle.jafcp.com`
- Look for the `alerts` and `alerts_scheduler` containers

### 5. Access the Application

Navigate to: `https://noaa.jafcp.com`

You should see the Alerts web interface after authenticating with your basic auth credentials.

## Troubleshooting

### Service Not Accessible

1. **Check Traefik can see the service:**
   ```bash
   docker logs traefik | grep alerts
   ```

2. **Verify network connectivity:**
   ```bash
   docker network inspect traefik
   ```
   Ensure the `alerts` container is listed in the network.

3. **Check Traefik dashboard:**
   Navigate to `https://traefik.jafcp.com` to see if the route is registered.

### DNS Issues

Ensure `noaa.jafcp.com` resolves to your Traefik host:
```bash
nslookup noaa.jafcp.com
```

### SSL Certificate Issues

If using Let's Encrypt, ensure Traefik can complete the ACME challenge for `noaa.jafcp.com`. Check Traefik logs:
```bash
docker logs traefik
```

### Database Locked Errors

If you see "database is locked" errors:
1. Ensure only one instance is running (no duplicate containers)
2. Restart services:
   ```bash
   docker compose restart
   ```

### Cannot View Logs in Dozzle

1. Ensure Dozzle has access to Docker socket:
   ```bash
   docker inspect dozzle | grep -A 5 Mounts
   ```
   Should show `/var/run/docker.sock` mounted.

2. Verify Dozzle can see all containers - it should have access to the entire Docker daemon.

## Advanced Configuration

### Adding Traefik Labels to SQLite Browser

To access SQLite Browser through Traefik, add labels to the `sqlitebrowser` service:

```yaml
sqlitebrowser:
  image: lscr.io/linuxserver/sqlitebrowser:latest
  container_name: sqlitebrowser
  labels:
    - traefik.enable=true
    - traefik.http.routers.sqlitebrowser.entrypoints=websecure
    - traefik.http.routers.sqlitebrowser.rule=Host(`sqlite.jafcp.com`)
    - traefik.http.routers.sqlitebrowser.middlewares=simpleAuth
    - traefik.http.services.sqlitebrowser.loadbalancer.server.port=3000
  networks:
    - traefik
  # ... rest of configuration
```

### Using Different Authentication

To use a different authentication method (e.g., Authelia), replace the `simpleAuth` middleware:

```yaml
- traefik.http.routers.alerts.middlewares=authelia@file
```

### Internal Network for Service Communication

If you need services to communicate on a private network (isolated from Traefik), you can use multiple networks:

```yaml
services:
  alerts:
    networks:
      - traefik    # For Traefik access
      - internal   # For inter-service communication

networks:
  traefik:
    external: true
  internal:
    driver: bridge
```

## Security Considerations

1. **Basic Authentication**: The current setup uses Basic Auth configured in Traefik. Consider using a more robust authentication solution like Authelia or OAuth2 for production.

2. **Database Access**: SQLite Browser is not exposed through Traefik by default. Only expose it if needed and use strong authentication.

3. **Log Sensitivity**: Logs may contain sensitive information. Ensure Dozzle is properly secured with authentication.

4. **Environment Variables**: Never commit `.env` files with real credentials to version control.

5. **Network Isolation**: Services on the `traefik` network can communicate with each other. Use additional networks if you need isolation.

## Migration from Standalone to Traefik

If migrating from the standalone configuration:

1. **Backup data:**
   ```bash
   cp -r data data.backup
   ```

2. **Stop old containers:**
   ```bash
   docker compose down
   ```

3. **Update docker-compose.yml** (already done in this configuration)

4. **Start with new configuration:**
   ```bash
   docker compose up -d
   ```

5. **Verify in logs:**
   Check Dozzle to ensure services started correctly and the scheduler is running.

## References

- [Traefik Documentation](https://doc.traefik.io/traefik/)
- [Docker Networks](https://docs.docker.com/network/)
- [Dozzle Documentation](https://dozzle.dev/)
- [Alerts Documentation](./documentation/INDEX.md)

## Support

For issues specific to the Alerts application, see the main [README.md](./README.md) and [INSTALL.md](./INSTALL.md).

For Traefik-specific issues, consult the [Traefik documentation](https://doc.traefik.io/traefik/).
