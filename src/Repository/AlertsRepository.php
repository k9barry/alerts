<?php
namespace App\Repository;

use App\DB\Connection;
use PDO;

final class AlertsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    public function replaceIncoming(array $alerts): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM incoming_alerts');
            $stmt = $this->db->prepare('INSERT INTO incoming_alerts (id, json, same_array, ugc_array) VALUES (:id, :json, :same, :ugc)');
            foreach ($alerts as $a) {
                $stmt->execute([
                    ':id' => $a['id'],
                    ':json' => json_encode($a, JSON_UNESCAPED_SLASHES),
                    ':same' => json_encode($a['same_array'] ?? []),
                    ':ugc' => json_encode($a['ugc_array'] ?? []),
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

        $rows = $this->db->query('SELECT id, json, same_array, ugc_array FROM incoming_alerts WHERE id IN (' . str_repeat('?,', count($newIds)-1) . '?)')->fetchAll(PDO::FETCH_ASSOC);
        $ins = $this->db->prepare('INSERT OR IGNORE INTO pending_alerts (id, json, same_array, ugc_array) VALUES (:id, :json, :same, :ugc)');
        $count = 0;
        foreach ($rows as $r) {
            $ins->execute([
                ':id' => $r['id'],
                ':json' => $r['json'],
                ':same' => $r['same_array'],
                ':ugc' => $r['ugc_array'],
            ]);
            $count += (int)$ins->rowCount();
        }
        return $count;
    }

    public function replaceActiveWithIncoming(): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM active_alerts');
            $this->db->exec('INSERT INTO active_alerts (id, json, same_array, ugc_array) SELECT id, json, same_array, ugc_array FROM incoming_alerts');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getPending(): array
    {
        return $this->db->query('SELECT id, json, same_array, ugc_array FROM pending_alerts')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function deletePendingById(string $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM pending_alerts WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
