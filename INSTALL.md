# Install

Supervisor has been removed. The scheduler now runs locally without Supervisor.

- Local: php scripts/scheduler.php
- Docker: docker compose up --build -dation

## Requirements
- Docker and Docker Compose
- Internet access for pulling images and weather.gov API

## Setup
1. Clone repository and enter directory
2. Copy env template and adjust
```sh
cp .env.example .env
```

3. (Optional) Configure local CA bundle for SSL
    - Download cacert.pem from https://curl.se/ca/cacert.pem
    - Save it to certs/cacert.pem (create the certs/ directory if needed)
    - Add to .env:

```
SSL_CERT_FILE=certs/cacert.pem
CURL_CA_BUNDLE=certs/cacert.pem
```

4. Build and start stack
```sh
docker compose up --build -d
```
4. Access services
- App GUI: http://localhost:8080
- Logs (Dozzle): http://localhost:9999
- SQLiteBrowser: data is mounted to ./data

## Data locations
- SQLite DB: ./data/alerts.sqlite (bind mounted to /data)
- Logs: stream to stdout for Dozzle; local files under ./logs if LOG_CHANNEL=file

## Upgrading
```sh
docker compose pull
docker compose up --build -d
```

## Troubleshooting
- Ensure ports 8080 and 9999 are free
- Check Dozzle for structured logs
- Validate .env values

### Line Ending Issues
If you encounter errors like `env: 'bash\r': No such file or directory`, this indicates CRLF line ending issues:

**For developers on Windows:**
```sh
# Configure git to checkout with LF endings
git config --global core.autocrlf input
```

**For developers on macOS/Linux:**
```sh
# Configure git to use LF endings
git config --global core.autocrlf input
```

**To fix existing files with CRLF:**
```sh
# Convert shell scripts to LF
dos2unix docker/entrypoint.sh

# Or using sed
sed -i 's/\r$//' docker/entrypoint.sh
```

The repository includes a `.gitattributes` file that enforces LF line endings for shell scripts and text files. The Docker build process also includes `dos2unix` to ensure correct line endings at build time.
