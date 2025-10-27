<?php
namespace App\Repository;

use App\DB\Connection;
use PDO;

final class UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Connection::get();
    }

    public function all(): array
    {
        return $this->db->query('SELECT * FROM user_data ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM user_data WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('INSERT INTO user_data (first_name, last_name, email, pushover_token, pushover_user, same_array, ugc_array, latitude, longitude, alert_location, created_at, updated_at) VALUES (:first_name, :last_name, :email, :pushover_token, :pushover_user, :same_array, :ugc_array, :latitude, :longitude, :alert_location, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $stmt->execute([
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':email' => $data['email'],
            ':pushover_token' => $data['pushover_token'],
            ':pushover_user' => $data['pushover_user'],
            ':same_array' => json_encode($data['same_array'] ?? []),
            ':ugc_array' => json_encode($data['ugc_array'] ?? []),
            ':latitude' => $data['latitude'] ?? null,
            ':longitude' => $data['longitude'] ?? null,
            ':alert_location' => $data['alert_location'] ?? null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare('UPDATE user_data SET first_name=:first_name, last_name=:last_name, email=:email, pushover_token=:pushover_token, pushover_user=:pushover_user, same_array=:same_array, ugc_array=:ugc_array, latitude=:latitude, longitude=:longitude, alert_location=:alert_location, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $stmt->execute([
            ':id' => $id,
            ':first_name' => $data['first_name'],
            ':last_name' => $data['last_name'],
            ':email' => $data['email'],
            ':pushover_token' => $data['pushover_token'],
            ':pushover_user' => $data['pushover_user'],
            ':same_array' => json_encode($data['same_array'] ?? []),
            ':ugc_array' => json_encode($data['ugc_array'] ?? []),
            ':latitude' => $data['latitude'] ?? null,
            ':longitude' => $data['longitude'] ?? null,
            ':alert_location' => $data['alert_location'] ?? null,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM user_data WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
