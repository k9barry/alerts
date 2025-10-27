# Installation

## Requirements
- Docker and Docker Compose
- Internet access for pulling images and weather.gov API

## Setup
1. Clone repository and enter directory
2. Copy env template and adjust
```sh
cp .env.example .env
```
3. Build and start stack
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
