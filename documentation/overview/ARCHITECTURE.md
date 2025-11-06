# Architecture Overview

The Alerts application follows a clean layered architecture with clear separation of concerns. This document describes the system design, component interactions, and data flow.

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    External Services                         │
├──────────────────┬──────────────────┬──────────────────────┤
│  weather.gov API │  Pushover API    │  ntfy Server         │
└────────┬─────────┴────────┬─────────┴──────────┬───────────┘
         │                  │                     │
         │ HTTP GET         │ HTTP POST           │ HTTP POST
         │                  │                     │
┌────────▼──────────────────▼─────────────────────▼───────────┐
│                     Alerts Application                        │
│  ┌────────────────────────────────────────────────────────┐ │
│  │              Scheduler (ConsoleApp)                     │ │
│  │  Continuous loop: poll → process → notify → sleep      │ │
│  └──┬──────────────────────────────────────────────┬──────┘ │
│     │                                               │         │
│  ┌──▼──────────────────┐                ┌──────────▼──────┐ │
│  │  Service Layer      │                │  Service Layer   │ │
│  │  ┌───────────────┐  │                │  ┌─────────────┐│ │
│  │  │ AlertFetcher  │  │                │  │AlertProc-   ││ │
│  │  │               │  │                │  │essor        ││ │
│  │  └───────┬───────┘  │                │  └──────┬──────┘│ │
│  │          │           │                │         │        │ │
│  │  ┌───────▼───────┐  │                │  ┌──────▼──────┐│ │
│  │  │WeatherClient  │  │                │  │Pushover/    ││ │
│  │  │+ RateLimiter  │  │                │  │Ntfy Notifiers│ │
│  │  └───────────────┘  │                │  └─────────────┘│ │
│  └──────────┬───────────┘                └─────────┬───────┘ │
│             │                                       │          │
│  ┌──────────▼───────────────────────────────────────▼──────┐ │
│  │               Repository Layer                            │ │
│  │              (AlertsRepository)                           │ │
│  └──────────────────────────┬────────────────────────────────┘ │
│                             │                                   │
│  ┌──────────────────────────▼────────────────────────────────┐ │
│  │               Database Layer (Connection)                  │ │
│  │                     PDO + SQLite                           │ │
│  └──────────────────────────┬────────────────────────────────┘ │
│                             │                                   │
└─────────────────────────────┼───────────────────────────────────┘
                              │
                    ┌─────────▼─────────┐
                    │  SQLite Database  │
                    │   (6 Tables)      │
                    └───────────────────┘
```

## Architectural Layers

### 1. Configuration Layer (Config.php)

**Purpose**: Centralized application configuration

**Responsibilities**:
- Load environment variables into strongly-typed static properties
- Provide single source of truth for all configuration
- Validate and normalize configuration values

**Design Decisions**:
- Static properties for performance and convenience
- Immutable after initialization via `initFromEnv()`
- No external dependencies

### 2. Data Access Layer

#### Database Connection (DB/Connection.php)

**Purpose**: Singleton PDO instance for SQLite

**Responsibilities**:
- Provide configured PDO connection
- Enable WAL mode for better concurrency
- Enable foreign keys
- Set error mode to exceptions

**Design Decisions**:
- Singleton pattern to prevent multiple connections
- Lazy initialization
- WAL mode for concurrent reads during writes

#### Repository (Repository/AlertsRepository.php)

**Purpose**: Data access abstraction for alert tables

**Responsibilities**:
- CRUD operations on alert tables
- Transaction management
- Complex queries (diffs, bulk operations)
- JSON encoding/decoding for arrays

**Key Methods**:
- `replaceIncoming(array $alerts)` - Atomic replacement of incoming snapshot
- `queuePendingForNew()` - Diff incoming vs active, queue new alerts
- `getPending()` - Fetch alerts ready for notification
- `insertSentResult()` - Record notification outcome
- `replaceActiveWithIncoming()` - Promote incoming to active

**Design Decisions**:
- Transactions for consistency
- Prepared statements for security
- Returns arrays (not objects) for simplicity
- Idempotent operations where possible

### 3. HTTP Layer

#### WeatherClient (Http/WeatherClient.php)

**Purpose**: HTTP client for weather.gov API

**Responsibilities**:
- Fetch active alerts from weather.gov
- HTTP caching via ETag/Last-Modified
- Rate limiting integration
- Error handling

**Design Decisions**:
- Guzzle for HTTP operations
- Returns empty array on errors (fail-safe)
- Stores ETag/Last-Modified for efficient polling
- Custom User-Agent with contact email

#### RateLimiter (Http/RateLimiter.php)

**Purpose**: In-process rate limiting

**Responsibilities**:
- Track request timestamps in rolling 60-second window
- Sleep when rate limit would be exceeded
- Automatic cleanup of old timestamps

**Algorithm**:
1. Remove timestamps older than 60 seconds
2. If count >= max, sleep until earliest timestamp ages out
3. Record new timestamp

**Design Decisions**:
- Simple array-based implementation
- No external storage needed
- Microsecond precision

### 4. Service Layer

#### AlertFetcher (Service/AlertFetcher.php)

**Purpose**: Orchestrate alert fetching and storage

**Responsibilities**:
- Call WeatherClient to fetch data
- Parse and normalize GeoJSON features
- Extract SAME/UGC codes into arrays
- Store in incoming_alerts table

**Data Flow**:
1. Fetch from API via WeatherClient
2. Extract features from GeoJSON
3. Normalize structure (preserve full JSON + extract fields)
4. Store via repository

**Design Decisions**:
- Skip empty responses to preserve existing data
- Store full feature JSON for future extensibility
- Extract SAME/UGC into separate arrays for filtering

#### AlertProcessor (Service/AlertProcessor.php)

**Purpose**: Process alerts and dispatch notifications

**Responsibilities**:
- Diff incoming vs active alerts
- Filter by SAME/UGC codes
- Send notifications via configured channels
- Record outcomes in sent_alerts

**Processing Flow**:
1. `diffAndQueue()` - Identify new alerts, add to pending
2. `processPending()` - For each pending alert:
   - Check geographic filters (SAME/UGC codes)
   - Send to enabled notification channels (parallel)
   - Record result in sent_alerts
   - Remove from pending (even on failure to prevent queue clog)

**Design Decisions**:
- Geographic filtering prevents notification spam
- Multi-channel support with independent results
- Graceful degradation (failed channel doesn't block others)
- Always remove from pending to prevent infinite retry

#### Notifiers

##### PushoverNotifier (Service/PushoverNotifier.php)

**Purpose**: Send notifications via Pushover

**Features**:
- Retry logic (up to 3 attempts)
- Configurable pacing (default 2 seconds between sends)
- Rich messages via MessageBuilderTrait
- Clickable URLs to NWS alert details
- Detailed result metadata

**Rate Limiting**:
- Tracks `lastSentAt` timestamp
- Sleeps to maintain minimum gap between sends
- Prevents API abuse

##### NtfyNotifier (Service/NtfyNotifier.php)

**Purpose**: Send notifications via ntfy

**Features**:
- HTTP POST with special headers
- Bearer token or Basic auth
- Custom priority, tags, click actions
- Title prefix support
- Conservative message length limits

**Design Decisions**:
- Direct HTTP (not SDK) for simplicity
- Title from event name (concise)
- Message from headline (descriptive)
- Optional click URL to NWS details

##### MessageBuilderTrait (Service/MessageBuilderTrait.php)

**Purpose**: Shared message formatting logic

**Features**:
- Title: `[EVENT] Headline`
- Message includes:
  - Severity/Certainty/Urgency (S/C/U)
  - Status/Message Type/Category
  - Area description
  - Effective and expiration times (localized)
  - Full description
  - Instructions (if present)
- Timezone-aware time formatting

### 5. Scheduler Layer (Scheduler/ConsoleApp.php)

**Purpose**: Console application and command definitions

**Commands**:

1. **poll** - Single poll cycle
   - Fetch and store incoming
   - Diff and queue pending
   - Process pending

2. **vacuum** - Manual database VACUUM

3. **run-scheduler** - Continuous scheduler loop
   - Infinite loop with configurable interval
   - Executes poll cycle
   - Replaces active with incoming
   - Periodic VACUUM
   - Error handling (continues on failure)

**Design Decisions**:
- Symfony Console for CLI framework
- Anonymous classes for commands (no separate files needed)
- Try-catch in loop prevents crashes
- Sleep between cycles

### 6. Logging Layer (Logging/LoggerFactory.php)

**Purpose**: Centralized logging configuration

**Features**:
- Monolog-based
- JSON formatter for structured logs
- Introspection processor (adds file/line/class context)
- Configurable output (stdout or file)
- Configurable log level

**Design Decisions**:
- Singleton pattern
- JSON format for parsing and filtering
- Stdout default for Docker (Dozzle can read it)
- Introspection for debugging

## Data Flow

### Alert Processing Cycle

```
1. Timer Triggers
   ↓
2. AlertFetcher.fetchAndStoreIncoming()
   - WeatherClient.fetchActive() → HTTP GET with rate limit
   - Parse GeoJSON features
   - Extract SAME/UGC codes
   - AlertsRepository.replaceIncoming()
   ↓
3. AlertProcessor.diffAndQueue()
   - Get incoming IDs
   - Get active IDs
   - Calculate diff (new = incoming - active)
   - AlertsRepository.queuePendingForNew()
   ↓
4. AlertProcessor.processPending()
   - Get pending alerts
   - Filter by SAME/UGC codes
   - For matching alerts:
     * Send to Pushover (if enabled)
     * Send to ntfy (if enabled)
     * Record result in sent_alerts
   - Delete from pending
   ↓
5. AlertsRepository.replaceActiveWithIncoming()
   - Replace active_alerts with incoming_alerts
   ↓
6. Sleep for POLL_MINUTES
   ↓
7. Repeat from step 1
```

### Database State Transitions

```
┌─────────────────┐
│ incoming_alerts │ ← Always replaced with latest API fetch
└────────┬────────┘
         │
         │ Diff against active
         ↓
┌────────▼────────┐
│ pending_alerts  │ ← New alerts queued here
└────────┬────────┘
         │
         │ Process & notify
         ↓
┌────────▼────────┐
│  sent_alerts    │ ← Permanent record of notifications
└─────────────────┘
         ↑
┌────────┴────────┐
│ active_alerts   │ ← Replaced with incoming after processing
└─────────────────┘
```

## Design Patterns

### Singleton
- **Connection**: Single database connection
- **LoggerFactory**: Single logger instance
- **Config**: Initialized once, used everywhere

### Repository Pattern
- **AlertsRepository**: Abstracts database operations
- Benefits: Testability, centralized query logic, transaction management

### Dependency Injection
- Services receive dependencies via constructor
- Benefits: Testability, flexibility, clear dependencies

### Trait (Mixin)
- **MessageBuilderTrait**: Shared notification message logic
- Benefits: Code reuse without inheritance

### Strategy Pattern (Implicit)
- Multiple notifier implementations (Pushover, ntfy)
- Same interface: `send()` or `notifyDetailed()`
- Benefits: Easily add new notification channels

## Error Handling Strategy

### Fail-Safe Defaults
- Empty API response → Keep existing incoming_alerts
- HTTP error → Return empty features array
- Notification failure → Log and continue (don't crash loop)

### Retry Logic
- **Pushover**: Up to 3 attempts with exponential backoff implicit in pacing
- **Weather API**: No retries (rely on next poll cycle)

### Transaction Safety
- Database operations wrapped in transactions
- Rollback on error
- Prevents partial state

### Logging
- All errors logged with context
- Structured JSON for easy parsing
- Introspection adds file/line for debugging

## Scalability Considerations

### Current Design (Single Instance)
- In-memory rate limiter (not shared across processes)
- SQLite with WAL mode (good for single writer, multiple readers)
- No locking needed (single scheduler process)

### Potential Bottlenecks
- SQLite write concurrency (single writer)
- HTTP API rate limits
- Notification pacing delays

### Future Improvements
- Redis for shared rate limiting (multi-instance)
- PostgreSQL for better write concurrency
- Queue system (RabbitMQ, Redis) for notifications
- Horizontal scaling with job distribution

## Security Considerations

### Input Validation
- Environment variables validated and type-cast
- Database prepared statements (SQL injection protection)
- JSON parsing with error handling

### Secrets Management
- Credentials in environment variables (never in code)
- `.env` file excluded from git
- Docker secrets support ready (use env_file)

### API Rate Limiting
- Respects weather.gov rate limits
- Prevents API abuse/blocking

### Error Information Disclosure
- Errors logged but not exposed in UI
- Stack traces only in debug mode

## Testing Strategy

### Unit Tests
- Mock HTTP clients for isolation
- Test business logic without external dependencies
- PHPUnit for assertions

### Test Coverage
- **PushoverRetryAndFailureTest**: Retry logic
- **NtfyNotifierTest**: ntfy notification sending
- **NtfyFailureTest**: ntfy error handling

### Test Principles
- Arrange-Act-Assert pattern
- Mock external services
- Test error paths
- Verify retry behavior

## Configuration Philosophy

### Environment-Based
- All configuration via environment variables
- No hardcoded values
- `.env.example` documents all options

### Sensible Defaults
- Works out of the box for common cases
- Production-ready defaults
- Override only what's necessary

### Type Safety
- Config values strongly typed
- Validation on load
- Fail fast on invalid config

## Future Architecture Evolution

### Potential Enhancements
1. **Web UI**: Replace placeholder index.php with real UI
2. **User Management**: Multi-user support with per-user filters
3. **Alert History**: Search and filter sent alerts
4. **Statistics Dashboard**: Alert counts, notification success rates
5. **Webhooks**: Generic webhook notifier for integrations
6. **Email Notifier**: Additional notification channel
7. **SMS/Twilio**: Critical alert escalation
8. **Alert Grouping**: Batch similar alerts
9. **Silence Rules**: Temporary notification suppression
10. **Alert Acknowledgment**: Track which alerts were seen

### Architectural Changes Needed
- **Authentication**: JWT or session-based
- **Multi-tenancy**: Tenant isolation in database
- **API Layer**: REST API for UI
- **Real-time Updates**: WebSockets for live alert feed
- **Job Queue**: Background job processing
