# Repository/AlertsRepository.php

Data access layer for alert tables with complex query operations.

## Location
`src/Repository/AlertsRepository.php`

## Purpose
Abstracts database operations for the four alert tables.

## Key Methods

### replaceIncoming(array $alerts)
Atomic replacement of incoming_alerts table:
1. BEGIN TRANSACTION
2. DELETE FROM incoming_alerts
3. INSERT all new alerts
4. COMMIT

### queuePendingForNew()
Identifies new alerts and queues them:
1. Get IDs from incoming_alerts
2. Get IDs from active_alerts
3. Calculate diff: new = incoming - active
4. INSERT OR IGNORE into pending_alerts
5. Return count

### getPending()
Returns all pending alerts for processing.

### insertSentResult(array $row, array $result)
Records notification result in sent_alerts (INSERT OR REPLACE).

### replaceActiveWithIncoming()
Updates baseline for next diff cycle:
1. BEGIN TRANSACTION
2. DELETE FROM active_alerts
3. INSERT FROM incoming_alerts
4. COMMIT

## Transaction Safety
All multi-statement operations wrapped in transactions with rollback on error.

## Data Format
All methods work with associative arrays (not objects).

See [DATABASE.md](../overview/DATABASE.md) for schema details.
