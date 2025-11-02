<?php
namespace App\Repository;

use App\DB\Connection;
use PDO;

/**
 * AlertsRepository
 *
 * Database access helpers for alert tables (incoming_alerts, pending_alerts, active_alerts, sent_alerts).
 */
final class AlertsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

  // Replace snapshot of incoming alerts with provided set (guarded by caller for empties)
    public function replaceIncoming(array $alerts): void
    {
      if (empty($alerts)) {
        return;
      }
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM incoming_alerts');
          $stmt = $this->db->prepare(
            'INSERT INTO incoming_alerts (
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

    public function getIncomingIds(): array
    {
        return $this->db->query('SELECT id FROM incoming_alerts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function getActiveIds(): array
    {
        return $this->db->query('SELECT id FROM active_alerts')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

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

    public function getPending(): array
    {
      return $this->db->query('SELECT * FROM pending_alerts')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function deletePendingById(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM pending_alerts WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

  public function insertSentResult(array $row, array $result): void
  {
    $stmt = $this->db->prepare('INSERT OR REPLACE INTO sent_alerts (
            id, type, status, msg_type, category, severity, certainty, urgency,
            event, headline, description, instruction, area_desc, sent, effective,
            onset, expires, ends, same_array, ugc_array, json,
            notified_at, result_status, result_attempts, result_error, pushover_request_id, user_id
        ) VALUES (
            :id, :type, :status, :msg_type, :category, :severity, :certainty, :urgency,
            :event, :headline, :description, :instruction, :area_desc, :sent, :effective,
            :onset, :expires, :ends, :same_array, :ugc_array, :json,
            CURRENT_TIMESTAMP, :result_status, :result_attempts, :result_error, :request_id, :user_id
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
      ':result_status' => $result['status'] ?? null,
      ':result_attempts' => $result['attempts'] ?? 0,
      ':result_error' => $result['error'] ?? null,
      ':request_id' => $result['request_id'] ?? null,
      ':user_id' => $result['user_id'] ?? null,
    ]);
  }
}
