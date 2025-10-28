# Composer Dependencies

This document describes the purpose and usage of all Composer libraries required by this project, following the existing
documentation style. Each section explains where the library is used in the codebase and provides example usage
patterns. Guzzle is documented in detail with common use cases and parameters.

- PHP (>= 8.1)
- guzzlehttp/guzzle (^7.9)
- monolog/monolog (^3.6)
- vlucas/phpdotenv (^5.6)
- symfony/console (^7.1)
- symfony/process (^7.1)
- respect/validation (^2.3)
- psr/log (^3.0)
- ramsey/uuid (^4.7)
- verifiedjoseph/ntfy-php-library (^4.7)

## PHP (>= 8.1)

- Purpose: Runtime for the application.
- Usage in project: All codebase. Leverages enums, typed properties, constructor property promotion, readonly, and
  fibers not required.

## guzzlehttp/guzzle (^7.9)

- Purpose: HTTP client for making external API requests.
- Where used:
    - src/Http/WeatherClient.php — performs HTTP requests to the weather API.
    - src/Service/PushoverNotifier.php — sends notifications to the Pushover API.
    - src/Http/RateLimiter.php — may wrap requests with delay/backoff policies.
- Why: Provides PSR-18-like client with robust middleware, retries, timeouts, and async support.

### Common Use Cases

1) Simple GET request

```
$client = new \GuzzleHttp\Client(['base_uri' => $baseUrl]);
$response = $client->get('/endpoint', [
    'query' => ['q' => 'value'],
    'headers' => ['Accept' => 'application/json'],
    'timeout' => 10,
]);
$data = json_decode((string) $response->getBody(), true);
```

2) POST JSON

```
$response = $client->post('/messages', [
    'json' => ['title' => 'Hi', 'body' => 'Message'],
    'headers' => ['Authorization' => 'Bearer '.$token],
]);
```

3) Retry with delay (using middleware example)

```
use GuzzleHttp\\HandlerStack;
use GuzzleHttp\\Middleware;
use Psr\\Http\\Message\\RequestInterface;
use Psr\\Http\\Message\\ResponseInterface;

$stack = HandlerStack::create();
$stack->push(Middleware::retry(
    function ($retries, RequestInterface $request, ResponseInterface $response = null, $exception = null) {
        if ($retries >= 3) return false;
        if ($exception) return true; // network error
        if ($response && $response->getStatusCode() >= 500) return true; // server error
        return false;
    },
    function ($retries) { return 1000 * (2 ** $retries); }
));
$client = new \GuzzleHttp\Client(['handler' => $stack]);
```

4) Asynchronous requests

```
$promise = $client->getAsync('/bulk', ['query' => ['ids' => '1,2,3']]);
$promise->then(function ($response) {
    // handle
});
$promise->wait();
```

### Frequently Used Request Options

- base_uri: Base URL used for relative requests.
- headers: Array of header name => value.
- query: Array for query string parameters.
- json: Array/object encoded to JSON and sent with application/json.
- form_params: application/x-www-form-urlencoded body.
- multipart: For file uploads and mixed parts.
- body: Raw string/stream resource body.
- auth: ['username','password'] for basic auth or ['username','password','digest'].
- timeout: Float seconds for total request timeout.
- connect_timeout: Float seconds for connection timeout.
- read_timeout: Float seconds for read timeout (if using curl, use 'read_timeout' via 'http_errors' off; Guzzle
  generally uses 'timeout' and 'connect_timeout').
- http_errors: bool. True (default) throws exceptions on 4xx/5xx (GuzzleHttp\Exception\ClientException/ServerException).
  Set to false to handle manually.
- allow_redirects: bool|array. Configure redirects (max, strict, referer, protocols, track_redirects).
- verify: bool|string. True uses system CA, false disables verification, or path to a CA bundle (e.g.,
  certs/cacert.pem).
- proxy: string|array proxy configuration.
- headers[User-Agent]: Customize UA string.
- sink: string|resource to save response body to file.

### Responses and Errors

- Response: Psr\Http\Message\ResponseInterface
    - getStatusCode(), getHeaders(), getBody()
- Exceptions: GuzzleHttp\Exception\RequestException (base), ClientException (4xx), ServerException (5xx),
  ConnectException (network)

### Project-specific Notes

- CA bundle located at certs/cacert.pem. Configure via verify => 'certs/cacert.pem' if needed (e.g., Windows without
  system CA).
- Rate limiting is implemented in src/Http/RateLimiter.php; integrate by wrapping client calls.
- WeatherClient centralizes HTTP access to external weather API; prefer using it over instantiating Guzzle directly
  elsewhere.
- PushoverNotifier sends POST requests to Pushover; ensure http_errors => false if you need to capture non-2xx responses
  without exceptions.

## monolog/monolog (^3.6)

- Purpose: Structured logging implementation.
- Where used: src/Logging/LoggerFactory.php creates a Monolog Logger. Consumed by services (AlertProcessor,
  AlertFetcher, PushoverNotifier, etc.).
- Typical usage: Handlers like StreamHandler; processors add context (e.g., UidProcessor). Log levels follow PSR-3.

## vlucas/phpdotenv (^5.6)

- Purpose: Loads environment variables from a .env file.
- Where used: src/bootstrap.php and/or Config.php to populate configuration (API keys, DB path, etc.).
- Typical usage: Dotenv::createImmutable(__DIR__.'/..')->load(); then getenv('KEY') or $_ENV['KEY'].

## symfony/console (^7.1)

- Purpose: CLI framework for building commands.
- Where used: src/Scheduler/ConsoleApp.php and scripts/scheduler.php expose CLI entry points.
- Typical usage: define Commands (execute()), register with Application, run().

## symfony/process (^7.1)

- Purpose: Execute and monitor external processes safely.
- Where used: May be used by scripts or Scheduler to invoke system tools if needed (no direct usages observed in tree,
  but included for extensibility).
- Typical usage: new Process(['php', 'scripts/migrate.php']); $process->mustRun();

## respect/validation (^2.3)

- Purpose: Input validation library with fluent API.
- Where used: Likely in service or repository layers to validate inputs (not directly visible in tree). Consider using
  for CLI argument validation or configuration checks.
- Typical usage: v::stringType()->length(1, 255)->validate($value).

## psr/log (^3.0)

- Purpose: PSR-3 interfaces for logging.
- Where used: Type hints across the app for logger dependencies to decouple from Monolog.

## ramsey/uuid (^4.7)

- Purpose: UUID generation and parsing.
- Where used: Repository or domain entities to assign unique identifiers to alerts.
- Typical usage: Uuid::uuid4()->toString(); Uuid::fromString($id).

## verifiedjoseph/ntfy-php-library (^4.7)

- Purpose: Client library for sending notifications to an ntfy server (https://ntfy.sh or self-hosted).
- Where used: src/Service/NtfyNotifier.php and wired from AlertProcessor.
- Environment flags and variables:
    - PUSHOVER_ENABLED=true|false — enables/disables Pushover sending.
    - NTFY_ENABLED=true|false — enables/disables ntfy sending.
    - NTFY_BASE_URL=https://ntfy.sh — server base URL.
    - NTFY_TOPIC=your-topic — target topic/channel.
    - NTFY_USER= (optional) ��� basic auth username when using auth.
    - NTFY_PASSWORD= (optional) — basic auth password.
    - NTFY_TOKEN= (optional) — bearer token alternative to user/pass.
    - NTFY_TITLE_PREFIX= (optional) — prefix added to notification title.

### Initialization

```
$client = new \Joseph\Ntfy\NtfyClient(rtrim(getenv('NTFY_BASE_URL') ?: 'https://ntfy.sh', '/'));
if ($token = getenv('NTFY_TOKEN')) {
    $client->setBearerToken($token);
} elseif (($user = getenv('NTFY_USER')) && ($pass = getenv('NTFY_PASSWORD'))) {
    $client->setBasicAuth($user, $pass);
}
```

### Publishing Messages

```
use Joseph\Ntfy\Message;

$msg = new Message(getenv('NTFY_TOPIC'), 'Body text');
$msg->title('Title');
$msg->priority(3);          // 1..5 (5=high)
$msg->tags(['warning']);    // emojis/tags
$msg->click('https://example.com');
$msg->attach('https://example.com/file.pdf');
$msg->delay('1h');          // delivery delay (server feature)

$client->publish($msg);
```

### Common Message Parameters

- title(string): Notification title.
- message(string): Body text (ctor second argument in Message(topic, message)).
- priority(int 1-5): Delivery priority (3=default, 5=highest).
- tags(string[]): Tags or emojis (e.g., ["warning","rotating_light"]).
- click(string URL): Link to open when the notification is tapped.
- attach(string URL or file path): Attach media or files (server must retrieve or receive file per library
  capabilities).
- delay(string): Scheduled delivery (e.g., "10m", "2h").

### Project-specific Use

- Implemented in NtfyNotifier::send(title, message, options) with options keys: tags, priority, click, attach, delay.
- AlertProcessor sends messages to all enabled channels (Pushover, ntfy) near-simultaneously when both PUSHOVER_ENABLED
  and NTFY_ENABLED are true.
- Title prefix can be set via NTFY_TITLE_PREFIX.

### Error Handling

- publish() will throw on network errors; code catches and logs exceptions.
- Ensure topic is non-empty and server URL is reachable. For self-hosted servers, configure authentication if required.
