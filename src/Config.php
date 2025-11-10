<?php
namespace App;

/**
 * Application configuration container
 *
 * Static properties populated from environment variables via Config::initFromEnv().
 * All configuration values are loaded from environment variables or use sensible defaults.
 * 
 * @package App
 * @author  Alerts Team
 * @license MIT
 */
final class Config
{
  /**
   * Get environment variable value
   * 
   * Checks $_ENV first, then getenv(). Returns default if not found.
   * 
   * @param string $key Environment variable name
   * @param mixed $default Default value if not found
   * @return mixed Environment variable value or default
   */
  private static function env(string $key, $default = null)
  {
    if (array_key_exists($key, $_ENV)) {
      return $_ENV[$key];
    }
    $val = getenv($key);
    return $val !== false ? $val : $default;
  }
    
    /** @var string Application name */
    public static string $appName;
    /** @var string Application version */
    public static string $appVersion;
    /** @var string Contact email address */
    public static string $contactEmail;

    /** @var int Polling interval in minutes */
    public static int $pollMinutes;
    /** @var int API rate limit (requests per minute) */
    public static int $apiRatePerMinute;
    /** @var int Pushover pacing delay in seconds between requests */
    public static int $pushoverRateSeconds;
    /** @var int Database VACUUM interval in hours */
    public static int $vacuumHours;

    /** @var string Path to SQLite database file */
    public static string $dbPath;

    /** @var string Logging channel (stdout, file, etc.) */
    public static string $logChannel;
    /** @var string Logging level (debug, info, warning, error) */
    public static string $logLevel;

    /** @var string Pushover API endpoint URL */
    public static string $pushoverApiUrl;
    /** @var string Weather.gov alerts API endpoint URL */
    public static string $weatherApiUrl;
    /** @var string NWS zones data file URL */
    public static string $zonesDataUrl;

    /** @var string Global Pushover user key (fallback) */
    public static string $pushoverUser;
    /** @var string Global Pushover app token (fallback) */
    public static string $pushoverToken;

  /** @var bool Enable Pushover notifications */
  public static bool $pushoverEnabled;
  /** @var bool Enable ntfy notifications */
  public static bool $ntfyEnabled;

  /** @var string ntfy server base URL */
  public static string $ntfyBaseUrl;
  /** @var string Global ntfy topic (fallback) */
  public static string $ntfyTopic;
  /** @var string|null Global ntfy username for authentication */
  public static ?string $ntfyUser;
  /** @var string|null Global ntfy password for authentication */
  public static ?string $ntfyPassword;
  /** @var string|null Global ntfy token for authentication */
  public static ?string $ntfyToken;
  /** @var string|null Optional prefix for ntfy notification titles */
  public static ?string $ntfyTitlePrefix;

  /** @var string IANA timezone identifier for timestamp localization */
  public static string $timezone;

    /**
     * Initialize configuration from environment variables
     * 
     * Loads all configuration values from environment variables using
     * sensible defaults. Must be called during application bootstrap.
     * 
     * Validates certain values like ntfy topic names.
     * 
     * @return void
     * @throws \RuntimeException If ntfy topic name is invalid when ntfy is enabled
     */
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
      self::$zonesDataUrl = (string)(self::env('ZONES_DATA_URL', 'https://www.weather.gov/source/gis/Shapefiles/County/bp18mr25.dbx'));

      self::$pushoverUser = (string)(self::env('PUSHOVER_USER', 'u-example'));
      self::$pushoverToken = (string)(self::env('PUSHOVER_TOKEN', 't-example'));

      // Feature flags
      self::$pushoverEnabled = filter_var(self::env('PUSHOVER_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
      self::$ntfyEnabled = filter_var(self::env('NTFY_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);

      // NTFY
      self::$ntfyBaseUrl = (string)(self::env('NTFY_BASE_URL', 'https://ntfy.sh'));
      self::$ntfyTopic = (string)(self::env('NTFY_TOPIC', ''));
      self::$ntfyUser = self::env('NTFY_USER') !== null ? (string)self::env('NTFY_USER') : null;
      self::$ntfyPassword = self::env('NTFY_PASSWORD') !== null ? (string)self::env('NTFY_PASSWORD') : null;
      self::$ntfyToken = self::env('NTFY_TOKEN') !== null ? (string)self::env('NTFY_TOKEN') : null;
      self::$ntfyTitlePrefix = self::env('NTFY_TITLE_PREFIX') !== null ? (string)self::env('NTFY_TITLE_PREFIX') : null;

      // Validate NTFY topic name if provided and NTFY is enabled
      if (self::$ntfyEnabled && !empty(self::$ntfyTopic) && !self::isValidNtfyTopicName(self::$ntfyTopic)) {
        throw new \RuntimeException(sprintf(
          'Invalid NTFY_TOPIC "%s": Topic names can only contain letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-)',
          self::$ntfyTopic
        ));
      }

      self::$timezone = (string)(self::env('TIMEZONE', 'America/Indianapolis'));
    }

  /**
   * Validates ntfy topic name character set.
   * Topic names can use letters (A-Z, a-z), numbers (0-9), underscores (_), and hyphens (-).
   * 
   * @param string $topic Topic name to validate
   * @return bool True if topic is valid, false otherwise
   */
  private static function isValidNtfyTopicName(string $topic): bool
  {
    $topic = trim($topic);
    if ($topic === '') {
      return false;
    }
    
    // Check if topic contains only allowed characters: letters, numbers, underscores, hyphens
    return preg_match('/^[A-Za-z0-9_-]+$/', $topic) === 1;
  }
}
