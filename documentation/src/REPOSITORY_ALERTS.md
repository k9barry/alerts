# src/Repository/AlertsRepository.php

Purpose: Encapsulate all database operations for alerts tables.

Key methods:
- replaceIncoming(array $alerts): replaces incoming_alerts snapshot with provided normalized alerts (skips if empty).
- getIncomingIds(), getActiveIds(): fetch IDs from respective tables.
- queuePendingForNew(): find IDs present in incoming_alerts but not active_alerts, and insert corresponding rows into pending_alerts. Returns inserted count.
- replaceActiveWithIncoming(): replace active_alerts content with incoming_alerts snapshot.
- getPending(): return all rows from pending_alerts.
- deletePendingById(string $id): remove a pending row.
- insertSentResult(array $row, array $result): insert or replace a row in sent_alerts with delivery result metadata.

Notes:
- Uses a shared PDO from Connection::get().
- Transactions ensure atomic replace operations; rollback on error.
