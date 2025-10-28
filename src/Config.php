<?php
namespace App;

final class Config
{
  private static function env(string $key, $default = null)
  {
    if (array_key_exists($key, $_ENV)) {
      return $_ENV[$key];
    }
    $val = getenv($key);
    return $val !== false ? $val : $default;
  }
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

  public static array $weatherAlerts = [];
  public static string $timezone;

    public static function initFromEnv(): void
    {
      self::$appName = (string)(self::env('APP_NAME', 'alerts'));
      self::$appVersion = (string)(self::env('APP_VERSION', '0.1.0'));
      self::$contactEmail = (string)(self::env('APP_CONTACT_EMAIL', 'you@example.com'));

      self::$pollMinutes = (int)(self::env('POLL_MINUTES', 3));
      self::$apiRatePerMinute = (int)(self::env('API_RATE_PER_MINUTE', 4));
      self::$pushoverRateSeconds = (int)(self::env('PUSHOVER_RATE_SECONDS', 2));
      self::$vacuumHours = (int)(self::env('VACUUM_HOURS', 24));

      self::$dbPath = (string)(self::env('DB_PATH', __DIR__ . '/../data/alerts.sqlite'));

      self::$logChannel = (string)(self::env('LOG_CHANNEL', 'stdout'));
      self::$logLevel = (string)(self::env('LOG_LEVEL', 'info'));

      self::$pushoverApiUrl = (string)(self::env('PUSHOVER_API_URL', 'https://api.pushover.net/1/messages.json'));
      self::$weatherApiUrl = (string)(self::env('WEATHER_API_URL', 'https://api.weather.gov/alerts/active'));

      self::$pushoverUser = (string)(self::env('PUSHOVER_USER', 'u-example'));
      self::$pushoverToken = (string)(self::env('PUSHOVER_TOKEN', 't-example'));

      self::$timezone = (string)(self::env('TIMEZONE', 'America/Indianapolis'));

      $codes = (string)self::env('WEATHER_ALERT_CODES', '');
      if ($codes !== '') {
        $parts = array_filter(array_map('trim', preg_split('/[\s,;]+/', $codes)));
        self::$weatherAlerts = array_values(array_unique($parts));
      } else {
        self::$weatherAlerts = [];
      }
    }
}
