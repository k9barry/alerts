<?php

declare(strict_types=1);

namespace App\Repository;

use App\DB\Connection;
use PDO;

/**
 * AlertsRepository
 *
 * Database access helpers for alert tables (incoming_alerts, pending_alerts, active_alerts, sent_alerts).
 * 
 * This repository provides methods for managing weather alerts through their lifecycle:
 * - incoming_alerts: Latest snapshot from API
 * - active_alerts: Current active alerts being tracked
 * - pending_alerts: New alerts queued for notification
 * - sent_alerts: Historical record of sent notifications
 * 
 * @package App\Repository
 * @author  Alerts Team
 * @license MIT
 */
final class AlertsRepository
{
    /**
     * Database connection
     *
     * @var PDO
     */
    private PDO $db;

    /**
     * Constructor - initializes database connection
     *
     * @return void
     */
    public function __construct()
    {
        $this->db = Connection::get();
    }

    /**
     * Replace the entire incoming_alerts table with a new set of alerts
     * 
     * This method performs a complete replacement:
     * 1. Deletes all existing incoming_alerts
     * 2. Inserts all provided alerts using INSERT OR REPLACE to handle duplicates
     * 3. Wraps operations in a transaction for atomicity
     * 
     * The method expects alerts in weather.gov API format with properties nested.
     * It extracts and normalizes the alert data before storing.
     * 
     * @param array $alerts Array of alert objects in weather.gov format
     * @return void
     * @throws \Throwable If database operation fails (transaction will rollback)
     */
    public function replaceIncoming(array $alerts): void
    {
      if (empty($alerts)) {
        return;
      }
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM incoming_alerts');
          $stmt = $this->db->prepare(
            'INSERT OR REPLACE INTO incoming_alerts (
                    id, type, status, msg_type, category, severity, certainty, urgency,
                    event, headline, description, instruction, area_desc, sent, effective,
                    onset, expires, ends, same_array, ugc_array, json
                 ) VALUES (
                    :id, :type, :status, :msg_type, :category, :severity, :certainty, :urgency,
                    :event, :headline, :description, :instruction, :area_desc, :sent, :effective,
                    :onset, :expires, :ends, :same, :ugc, :json
                 )'
          );
            foreach ($alerts as $a) {
              $props = $a['properties'] ?? [];
                $stmt->execute([
                    ':id' => $a['id'],
                  ':type' => $a['type'] ?? null,
                  ':status' => $props['status'] ?? null,
                  ':msg_type' => $props['messageType'] ?? ($props['msgType'] ?? null),
                  ':category' => $props['category'] ?? null,
                  ':severity' => $props['severity'] ?? null,
                  ':certainty' => $props['certainty'] ?? null,
                  ':urgency' => $props['urgency'] ?? null,
                  ':event' => $props['event'] ?? null,
                  ':headline' => $props['headline'] ?? null,
                  ':description' => $props['description'] ?? null,
                  ':instruction' => $props['instruction'] ?? null,
                  ':area_desc' => $props['areaDesc'] ?? null,
                  ':sent' => $props['sent'] ?? null,
                  ':effective' => $props['effective'] ?? null,
                  ':onset' => $props['onset'] ?? null,
                  ':expires' => $props['expires'] ?? null,
                  ':ends' => $props['ends'] ?? null,
                    ':same' => json_encode($a['same_array'] ?? []),
                    ':ugc' => json_encode($a['ugc_array'] ?? []),
                  ':json' => json_encode($a, JSON_UNESCAPED_SLASHES),
                ]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get all alert IDs from incoming_alerts table
     * 
     * @return array Array of alert ID strings
     */
    public function getIncomingIds(): array
    {
        return $this->db->query('SELECT id FROM incoming_alerts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Get all alert IDs from active_alerts table
     * 
     * @return array Array of alert ID strings
     */
    public function getActiveIds(): array
    {
        return $this->db->query('SELECT id FROM active_alerts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Queue new alerts to pending_alerts table
     * 
     * Compares incoming_alerts against active_alerts to find new alerts
     * that haven't been processed yet. New alerts are inserted into
     * pending_alerts using INSERT OR IGNORE to handle duplicates.
     * 
     * @return int Number of alerts queued
     */
    public function queuePendingForNew(): int
    {
        $incomingIds = $this->getIncomingIds();
        if (!$incomingIds) return 0;
        $activeIds = $this->getActiveIds();
        $newIds = array_values(array_diff($incomingIds, $activeIds));
        if (!$newIds) return 0;

      $placeholders = implode(',', array_fill(0, count($newIds), '?'));
      $stmt = $this->db->prepare('SELECT * FROM incoming_alerts WHERE id IN (' . $placeholders . ')');
      $stmt->execute($newIds);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $ins = $this->db->prepare('INSERT OR IGNORE INTO pending_alerts (
            id, type, status, msg_type, category, severity, certainty, urgency,
            event, headline, description, instruction, area_desc, sent, effective,
            onset, expires, ends, same_array, ugc_array, json
        ) VALUES (
            :id, :type, :status, :msg_type, :category, :severity, :certainty, :urgency,
            :event, :headline, :description, :instruction, :area_desc, :sent, :effective,
            :onset, :expires, :ends, :same, :ugc, :json
        )');
        $count = 0;
        foreach ($rows as $r) {
            $ins->execute([
                ':id' => $r['id'],
              ':type' => $r['type'] ?? null,
              ':status' => $r['status'] ?? null,
              ':msg_type' => $r['msg_type'] ?? null,
              ':category' => $r['category'] ?? null,
              ':severity' => $r['severity'] ?? null,
              ':certainty' => $r['certainty'] ?? null,
              ':urgency' => $r['urgency'] ?? null,
              ':event' => $r['event'] ?? null,
              ':headline' => $r['headline'] ?? null,
              ':description' => $r['description'] ?? null,
              ':instruction' => $r['instruction'] ?? null,
              ':area_desc' => $r['area_desc'] ?? null,
              ':sent' => $r['sent'] ?? null,
              ':effective' => $r['effective'] ?? null,
              ':onset' => $r['onset'] ?? null,
              ':expires' => $r['expires'] ?? null,
              ':ends' => $r['ends'] ?? null,
              ':same' => $r['same_array'] ?? '[]',
              ':ugc' => $r['ugc_array'] ?? '[]',
                ':json' => $r['json'],
            ]);
            $count += $ins->rowCount();
        }
        return $count;
    }

    /**
     * Replace active_alerts with all alerts from incoming_alerts
     * 
     * This method synchronizes the active_alerts table to match the current
     * incoming_alerts snapshot. It's called after processing new alerts to
     * update the baseline for future comparisons.
     * 
     * Operations are wrapped in a transaction for atomicity.
     * 
     * @return void
     * @throws \Throwable If database operation fails (transaction will rollback)
     */
    public function replaceActiveWithIncoming(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM active_alerts');
          $this->db->exec('INSERT INTO active_alerts (
                id, type, status, msg_type, category, severity, certainty, urgency,
                event, headline, description, instruction, area_desc, sent, effective,
                onset, expires, ends, same_array, ugc_array, json
            ) SELECT 
                id, type, status, msg_type, category, severity, certainty, urgency,
                event, headline, description, instruction, area_desc, sent, effective,
                onset, expires, ends, same_array, ugc_array, json
            FROM incoming_alerts');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get all pending alerts awaiting notification
     * 
     * @return array Array of alert rows as associative arrays
     */
    public function getPending(): array
    {
      return $this->db->query('SELECT * FROM pending_alerts')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete a specific alert from pending_alerts by ID
     * 
     * @param string $id Alert ID to delete
     * @return void
     */
    public function deletePendingById(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM pending_alerts WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Insert a notification result into sent_alerts table
     * 
     * Records that a notification was attempted for an alert, including
     * success/failure status, error messages, and attempt count.
     * Uses INSERT OR REPLACE to handle retries.
     * 
     * @param array $row Alert data from pending_alerts
     * @param array $result Notification result with keys: status, attempts, error, request_id, user_id, channels
     * @return void
     */
  public function insertSentResult(array $row, array $result): void
  {
    $userId = $result['user_id'] ?? null;
    $channels = $result['channels'] ?? [];
    
    // If $channels is empty, no records will be inserted into sent_alerts.
    // This is intentional: only attempted notification channels are recorded.
    foreach ($channels as $channelResult) {
      $channel = $channelResult['channel'] ?? 'unknown';
      $channelData = $channelResult['result'] ?? [];
      
      $stmt = $this->db->prepare('INSERT OR REPLACE INTO sent_alerts (
              id, type, status, msg_type, category, severity, certainty, urgency,
              event, headline, description, instruction, area_desc, sent, effective,
              onset, expires, ends, same_array, ugc_array, json,
              notified_at, result_status, result_attempts, result_error, pushover_request_id, user_id, channel
          ) VALUES (
              :id, :type, :status, :msg_type, :category, :severity, :certainty, :urgency,
              :event, :headline, :description, :instruction, :area_desc, :sent, :effective,
              :onset, :expires, :ends, :same_array, :ugc_array, :json,
              CURRENT_TIMESTAMP, :result_status, :result_attempts, :result_error, :request_id, :user_id, :channel
          )');
      $stmt->execute([
        ':id' => $row['id'],
        ':type' => $row['type'] ?? null,
        ':status' => $row['status'] ?? null,
        ':msg_type' => $row['msg_type'] ?? null,
        ':category' => $row['category'] ?? null,
        ':severity' => $row['severity'] ?? null,
        ':certainty' => $row['certainty'] ?? null,
        ':urgency' => $row['urgency'] ?? null,
        ':event' => $row['event'] ?? null,
        ':headline' => $row['headline'] ?? null,
        ':description' => $row['description'] ?? null,
        ':instruction' => $row['instruction'] ?? null,
        ':area_desc' => $row['area_desc'] ?? null,
        ':sent' => $row['sent'] ?? null,
        ':effective' => $row['effective'] ?? null,
        ':onset' => $row['onset'] ?? null,
        ':expires' => $row['expires'] ?? null,
        ':ends' => $row['ends'] ?? null,
        ':same_array' => $row['same_array'] ?? '[]',
        ':ugc_array' => $row['ugc_array'] ?? '[]',
        ':json' => $row['json'],
        ':result_status' => $channelData['status'] ?? null,
        ':result_attempts' => $channelData['attempts'] ?? 0,
        ':result_error' => $channelData['error'] ?? null,
        ':request_id' => $channelData['request_id'] ?? null,
        ':user_id' => $userId,
        ':channel' => $channel,
      ]);
    }
  }

  /**
   * Get the first matching zone's LAT and LON coordinates for a list of zone identifiers.
   * Searches zones table for matching STATE_ZONE, ZONE, or FIPS values.
   * 
   * Zone IDs are validated before querying to ensure they are safe:
   * - Must contain only alphanumeric characters (A-Z, a-z, 0-9)
   * - Maximum length of 10 characters
   * Invalid zone IDs are silently skipped.
   * 
   * @param array $zoneIds Array of zone identifiers (e.g., ["INZ040", "INC040", "018033"])
   * @return array{lat: float|null, lon: float|null} Coordinates or nulls if no match found
   */
  public function getZoneCoordinates(array $zoneIds): array
  {
    if (empty($zoneIds)) {
      return ['lat' => null, 'lon' => null];
    }
    
    // Build a query to match against STATE_ZONE (which contains comma-separated variants like "INC040,INZ040"),
    // ZONE (raw zone number), or FIPS
    $conditions = [];
    $params = [];
    
    foreach ($zoneIds as $idx => $zoneId) {
      $zoneId = trim((string)$zoneId);
      if ($zoneId === '') continue;
      
      // Validate zone ID format to prevent injection attacks
      // Valid formats:
      // - STATE_ZONE: 2-3 letters, optional 'C', followed by 1-4 digits (e.g., INC040, INZ040, OHC001)
      // - FIPS: 5-6 digit numeric string (e.g., 018033, 39001)
      // - Zone code: alphanumeric up to 10 characters
      if (!$this->isValidZoneId($zoneId)) {
        continue; // Skip invalid zone IDs silently
      }
      
      $paramName = ':zone' . $idx;
      $params[$paramName] = $zoneId;
      
      // Check if STATE_ZONE contains this ID (handles comma-separated values)
      // Also check ZONE and FIPS columns for direct matches
      $conditions[] = "("
        . "STATE_ZONE = {$paramName} "
        . "OR STATE_ZONE LIKE {$paramName} || ',%' "
        . "OR STATE_ZONE LIKE '%,' || {$paramName} "
        . "OR STATE_ZONE LIKE '%,' || {$paramName} || ',%' "
        . "OR ZONE = {$paramName} "
        . "OR FIPS = {$paramName}"
        . ")";
    }
    
    if (empty($conditions)) {
      return ['lat' => null, 'lon' => null];
    }
    
    $sql = 'SELECT LAT, LON FROM zones WHERE ' . implode(' OR ', $conditions) . ' AND LAT IS NOT NULL AND LON IS NOT NULL LIMIT 1';
    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if ($result && isset($result['LAT'], $result['LON'])) {
      return ['lat' => (float)$result['LAT'], 'lon' => (float)$result['LON']];
    }
    
    return ['lat' => null, 'lon' => null];
  }

  /**
   * Validate a zone ID to ensure it is safe for database queries.
   * 
   * This validation ensures zone IDs are:
   * - Alphanumeric characters only (letters A-Z, a-z and digits 0-9)
   * - Maximum 10 characters in length
   * 
   * This covers common zone ID formats including:
   * - STATE_ZONE: e.g., INC040, INZ040, OHC001
   * - FIPS codes: e.g., 018033, 39001
   * - Zone identifiers: e.g., Z040, C001
   * 
   * @param string $zoneId Zone identifier to validate
   * @return bool True if valid, false otherwise
   */
  private function isValidZoneId(string $zoneId): bool
  {
    // Maximum reasonable length for a zone ID
    if (strlen($zoneId) > 10) {
      return false;
    }

    // Alphanumeric only
    if (!preg_match('/^[A-Za-z0-9]+$/', $zoneId)) {
      return false;
    }

    // If numeric-only, treat as FIPS: must be 5 or 6 digits
    if (ctype_digit($zoneId)) {
      return (strlen($zoneId) === 5 || strlen($zoneId) === 6);
    }

    // Optionally enforce STATE_ZONE-like pattern: 2–3 letters, optional 'C', then 1–4 digits
    // while still allowing other simple alphanumeric IDs up to length 10
    // If it matches a stricter known pattern, accept
    if (preg_match('/^[A-Za-z]{2,3}C?\d{1,4}$/i', $zoneId)) {
      return true;
    }

    // Otherwise, allow generic alphanumeric up to 10 chars
    return true;
  }
}

