# Weather Alerts System

A PHP-based application that fetches weather alerts from the [weather.gov API](https://api.weather.gov/alerts) and stores them in a SQLite database. The system is containerized using Docker and includes real-time log monitoring with Dozzle and database browsing capabilities.

## Features

- **Automated Alert Fetching**: Continuously pulls weather alerts from the NWS API
- **Rate Limiting**: Respects API rate limits (max 4 calls per minute)
- **SQLite Database**: Stores alerts in a structured SQLite database
- **Docker Compose**: Easy deployment with multiple services
- **Logging**: Comprehensive logging with Dozzle for real-time monitoring
- **Database Browser**: Built-in SQLite browser for easy data inspection
- **Error Handling**: Robust error handling and recovery mechanisms
- **Security**: Best practices for secure API access and data storage

## Services

The application consists of three Docker services:

1. **alerts**: Main application that fetches and stores weather alerts
2. **sqlitebrowser**: Web-based SQLite database browser (accessible on port 3000)
3. **dozzle**: Real-time Docker log viewer (accessible on port 8080)

## Quick Start

See [INSTALL.md](INSTALL.md) for detailed installation instructions.

```bash
# Clone the repository
git clone https://github.com/k9barry/alerts.git
cd alerts

# Copy environment file
cp .env.example .env

# Edit .env with your email address
nano .env

# Start the services
docker-compose up -d

# View logs
docker-compose logs -f alerts
```

## Access Points

- **Dozzle (Logs)**: http://localhost:8080
- **SQLite Browser**: http://localhost:3000

## Database Schema

The application creates the following tables:

### alerts
Main table storing weather alert information:
- `id`: Unique alert identifier
- `event`: Type of weather event
- `severity`: Alert severity (Extreme, Severe, Moderate, Minor, Unknown)
- `urgency`: Response urgency (Immediate, Expected, Future, Past, Unknown)
- `headline`: Alert headline
- `description`: Detailed alert description
- `instruction`: Safety instructions
- `sent`, `effective`, `onset`, `expires`, `ends`: Various timestamps
- And many more fields...

### alert_zones
Stores affected geographic zones for each alert.

### api_calls
Tracks API calls for rate limiting and monitoring.

## Configuration

Configuration is managed through environment variables. See `.env.example` for available options:

- `APP_NAME`: Application name
- `APP_VERSION`: Application version
- `CONTACT_EMAIL`: Your contact email (required by weather.gov API)
- `API_RATE_LIMIT`: Maximum API calls per period (default: 4)
- `API_RATE_PERIOD`: Rate limit period in seconds (default: 60)
- `LOG_LEVEL`: Logging level (DEBUG, INFO, WARNING, ERROR)

## Development

### Debug Mode

Debug mode is enabled by default. Set `LOG_LEVEL=DEBUG` in your `.env` file for verbose logging.

### Production Mode

For production:
1. Set `APP_ENV=production` in `.env`
2. Set `LOG_LEVEL=INFO` or `LOG_LEVEL=WARNING`
3. Ensure proper file permissions on data and logs directories

## Architecture

```
alerts/
├── src/              # PHP source code
│   ├── Database.php  # Database operations
│   ├── ApiClient.php # API client
│   ├── LoggerFactory.php # Logging setup
│   └── app.php       # Main application
├── config/           # Configuration files
├── data/             # SQLite database (created at runtime)
├── logs/             # Application logs
├── documents/        # Documentation
└── docker-compose.yml # Docker services definition
```

## Documentation

- [INSTALL.md](INSTALL.md) - Installation guide
- [documents/](documents/) - Additional documentation and function references

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## API Reference

This application uses the [National Weather Service API](https://www.weather.gov/documentation/services-web-api).

## Support

For issues or questions, please open an issue on GitHub.
