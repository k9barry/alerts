<?php
namespace App;

final class Config
{
    public static string $appName;
    public static string $appVersion;
    public static string $contactEmail;

    public static int $pollMinutes;
    public static int $apiRatePerMinute;
    public static int $pushoverRateSeconds;
    public static int $vacuumHours;

    public static string $dbPath;

    public static string $logChannel;
    public static string $logLevel;

    public static string $pushoverApiUrl;
    public static string $weatherApiUrl;

    public static string $pushoverUser;
    public static string $pushoverToken;

    public static function initFromEnv(): void
    {
        self::$appName = getenv('APP_NAME') ?: 'alerts';
        self::$appVersion = getenv('APP_VERSION') ?: '0.1.0';
        self::$contactEmail = getenv('APP_CONTACT_EMAIL') ?: 'you@example.com';

        self::$pollMinutes = (int)(getenv('POLL_MINUTES') ?: 3);
        self::$apiRatePerMinute = (int)(getenv('API_RATE_PER_MINUTE') ?: 4);
        self::$pushoverRateSeconds = (int)(getenv('PUSHOVER_RATE_SECONDS') ?: 2);
        self::$vacuumHours = (int)(getenv('VACUUM_HOURS') ?: 24);

        self::$dbPath = getenv('DB_PATH') ?: __DIR__ . '/../data/alerts.sqlite';

        self::$logChannel = getenv('LOG_CHANNEL') ?: 'stdout';
        self::$logLevel = getenv('LOG_LEVEL') ?: 'info';

        self::$pushoverApiUrl = getenv('PUSHOVER_API_URL') ?: 'https://api.pushover.net/1/messages.json';
        self::$weatherApiUrl = getenv('WEATHER_API_URL') ?: 'https://api.weather.gov/alerts/active';

        self::$pushoverUser = getenv('PUSHOVER_USER') ?: (getenv('PUSHOVER_USER_EXAMPLE') ?: 'u-example');
        self::$pushoverToken = getenv('PUSHOVER_TOKEN') ?: (getenv('PUSHOVER_TOKEN_EXAMPLE') ?: 't-example');
    }
}
