# Documentation Index

Welcome to the Weather Alerts System documentation. This index will help you find the information you need.

## Getting Started

Start here if you're new to the project:

1. **[README.md](../README.md)** - Project overview, features, and quick start
2. **[INSTALL.md](../INSTALL.md)** - Detailed installation instructions
3. **[PROJECT_SUMMARY.md](../PROJECT_SUMMARY.md)** - Complete project summary and checklist

## User Guides

Documentation for using the system:

- **[Configuration.md](Configuration.md)** - Environment variables and configuration options
- **[Schema.md](Schema.md)** - Database schema, tables, and query examples

## Developer Documentation

Technical documentation for developers:

### Code Documentation

- **[ApiClient.md](ApiClient.md)** - API client class, methods, and usage examples
- **[Database.md](Database.md)** - Database class, schema management, and data operations
- **[LoggerFactory.md](LoggerFactory.md)** - Logging configuration and usage

### Development Guidelines

- **[copilot-instructions.md](../copilot-instructions.md)** - Coding standards and best practices
- **[Copilot-Create.md](Copilot-Create.md)** - Original project requirements

## Quick Reference

### Common Tasks

**Installation:**
```bash
git clone https://github.com/k9barry/alerts.git
cd alerts
cp .env.example .env
# Edit .env with your email
docker compose up -d
```

**View Logs:**
- Web UI: http://localhost:8080 (Dozzle)
- Command line: `docker compose logs -f alerts`

**View Database:**
- Web UI: http://localhost:3000 (SQLite Browser)
- Command line: `sqlite3 data/alerts.db`

**Stop Services:**
```bash
docker compose down
```

### Configuration Files

| File | Purpose |
|------|---------|
| `.env` | Environment configuration (not in git) |
| `.env.example` | Environment template |
| `config/config.php` | Configuration loader |
| `composer.json` | PHP dependencies |
| `docker-compose.yml` | Service definitions |
| `Dockerfile` | Application container |

### Source Files

| File | Purpose |
|------|---------|
| `src/app.php` | Main application entry point |
| `src/ApiClient.php` | Weather API client |
| `src/Database.php` | Database operations |
| `src/LoggerFactory.php` | Logging setup |

### Documentation Files

| File | Purpose |
|------|---------|
| `README.md` | Project overview |
| `INSTALL.md` | Installation guide |
| `PROJECT_SUMMARY.md` | Project completion summary |
| `copilot-instructions.md` | Development guidelines |
| `documents/ApiClient.md` | API client documentation |
| `documents/Configuration.md` | Configuration guide |
| `documents/Database.md` | Database class docs |
| `documents/LoggerFactory.md` | Logger documentation |
| `documents/Schema.md` | Database schema reference |
| `documents/INDEX.md` | This file |

## Documentation by Topic

### Installation & Setup

1. [INSTALL.md](../INSTALL.md) - Installation instructions
2. [Configuration.md](Configuration.md) - Configuration guide
3. [README.md](../README.md#quick-start) - Quick start guide

### API Integration

1. [ApiClient.md](ApiClient.md) - API client usage
2. [Configuration.md](Configuration.md#api-settings) - API configuration
3. [README.md](../README.md#api-reference) - API reference

### Database

1. [Schema.md](Schema.md) - Database schema and queries
2. [Database.md](Database.md) - Database class reference
3. [Configuration.md](Configuration.md#database-settings) - Database configuration

### Logging & Monitoring

1. [LoggerFactory.md](LoggerFactory.md) - Logging configuration
2. [Configuration.md](Configuration.md#logging-settings) - Log settings
3. [INSTALL.md](../INSTALL.md#dozzle-log-viewer) - Dozzle usage

### Docker & Deployment

1. [INSTALL.md](../INSTALL.md) - Docker setup
2. [README.md](../README.md#services) - Service descriptions
3. [INSTALL.md](../INSTALL.md#production-deployment) - Production deployment

### Development

1. [copilot-instructions.md](../copilot-instructions.md) - Coding standards
2. [PROJECT_SUMMARY.md](../PROJECT_SUMMARY.md#code-highlights) - Code examples
3. [ApiClient.md](ApiClient.md) - API client examples
4. [Database.md](Database.md) - Database examples

## Troubleshooting

### Common Issues

- **Installation Problems**: See [INSTALL.md](../INSTALL.md#troubleshooting)
- **Configuration Issues**: See [Configuration.md](Configuration.md#troubleshooting)
- **Database Issues**: See [Schema.md](Schema.md#database-maintenance)
- **Log Problems**: See [LoggerFactory.md](LoggerFactory.md#troubleshooting)

### Getting Help

1. Check the relevant documentation above
2. Review log files in `logs/alerts.log`
3. Use Dozzle at http://localhost:8080
4. Check database with SQLite Browser at http://localhost:3000
5. Open an issue on GitHub

## Additional Resources

### External Documentation

- [Weather.gov API](https://www.weather.gov/documentation/services-web-api)
- [Monolog](https://github.com/Seldaek/monolog)
- [Docker Compose](https://docs.docker.com/compose/)
- [SQLite](https://www.sqlite.org/docs.html)
- [PHP](https://www.php.net/docs.php)

### Project Resources

- [GitHub Repository](https://github.com/k9barry/alerts)
- [License](../LICENSE)

## Document Maintenance

This documentation is maintained alongside the code. When making changes:

1. Update relevant documentation files
2. Update this index if adding new documentation
3. Keep examples up to date with code changes
4. Add new troubleshooting sections as issues arise

---

**Last Updated:** October 2025

**Version:** 1.0.0

For questions or suggestions about this documentation, please open an issue on GitHub.
