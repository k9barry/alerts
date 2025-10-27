# src/Service/AlertProcessor.php

Purpose: Identify new alerts, filter them by configured codes, and send notifications.

Behavior:
- diffAndQueue(): uses AlertsRepository to insert into pending_alerts the alerts present in incoming but not in active; logs count.
- processPending(): reads pending, filters rows against Config::$weatherAlerts (SAME/UGC codes, case-insensitive).
  - Non-matching rows are deleted from pending.
  - Matching rows are sent via PushoverNotifier::notifyDetailed().
  - For each processed row, insert delivery result into sent_alerts and delete the pending row regardless of success to avoid clogging.

Usage:
- $processor = new AlertProcessor(); $processor->diffAndQueue(); $processor->processPending();
