<?php
// Restore a users backup JSON file into the users table.
// Usage: php scripts/restore_users_backup.php /path/to/user_backup/users_backup_YYYY-MM-DD_HH-MM-SS.json
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\DB\Connection;

$argv = $_SERVER['argv'];
if (!isset($argv[1]) || empty($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/restore_users_backup.php /path/to/users_backup_*.json\n");
    exit(2);
}

$file = $argv[1];
if (!file_exists($file)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(3);
}

$json = file_get_contents($file);
$rows = json_decode($json, true);
if (!is_array($rows)) {
    fwrite(STDERR, "Invalid JSON in {$file}\n");
    exit(4);
}

$pdo = Connection::get();
$inserted = 0;
$updated = 0;

foreach ($rows as $r) {
    if (!is_array($r)) continue;
    // Normalize keys
    $idx = $r['idx'] ?? null;
    $email = $r['Email'] ?? $r['email'] ?? '';
    $first = $r['FirstName'] ?? $r['firstName'] ?? $r['First'] ?? '';
    $last = $r['LastName'] ?? $r['lastName'] ?? $r['Last'] ?? '';
    $timezone = $r['Timezone'] ?? $r['timezone'] ?? 'America/New_York';
    $pushoverUser = $r['PushoverUser'] ?? $r['PushoverUser'] ?? '';
    $pushoverToken = $r['PushoverToken'] ?? $r['PushoverToken'] ?? '';
    $ntfyUser = $r['NtfyUser'] ?? $r['NtfyUser'] ?? '';
    $ntfyPassword = $r['NtfyPassword'] ?? $r['NtfyPassword'] ?? '';
    $ntfyToken = $r['NtfyToken'] ?? $r['NtfyToken'] ?? '';
    $zoneAlertRaw = $r['ZoneAlert'] ?? ($r['zoneAlert'] ?? '[]');

    // Ensure ZoneAlert is a JSON string
    if (is_array($zoneAlertRaw)) {
        $zoneAlert = json_encode($zoneAlertRaw);
    } elseif (is_string($zoneAlertRaw)) {
        // If it looks like JSON already, keep as-is; else encode
        $trim = trim($zoneAlertRaw);
        if (($trim !== '') && ($trim[0] === '[' || $trim[0] === '{')) {
            $zoneAlert = $zoneAlertRaw;
        } else {
            $zoneAlert = json_encode($zoneAlertRaw);
        }
    } else {
        $zoneAlert = json_encode($zoneAlertRaw);
    }

    // Decide update vs insert
    $found = null;
    if ($idx !== null) {
        $stmt = $pdo->prepare("SELECT idx FROM users WHERE idx = ?");
        $stmt->execute([$idx]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$found && $email) {
        $stmt = $pdo->prepare("SELECT idx FROM users WHERE Email = ?");
        $stmt->execute([$email]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($found && isset($found['idx'])) {
        // Update
        $stmt = $pdo->prepare("UPDATE users SET FirstName=?, LastName=?, Email=?, Timezone=?, PushoverUser=?, PushoverToken=?, NtfyUser=?, NtfyPassword=?, NtfyToken=?, ZoneAlert=?, UpdatedAt=CURRENT_TIMESTAMP WHERE idx=?");
        $stmt->execute([$first, $last, $email, $timezone, $pushoverUser, $pushoverToken, $ntfyUser, $ntfyPassword, $ntfyToken, $zoneAlert, $found['idx']]);
        $updated++;
    } else {
        // Insert (preserve idx if present)
        if ($idx !== null) {
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO users (idx, FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, ZoneAlert, CreatedAt, UpdatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([$idx, $first, $last, $email, $timezone, $pushoverUser, $pushoverToken, $ntfyUser, $ntfyPassword, $ntfyToken, $zoneAlert]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (FirstName, LastName, Email, Timezone, PushoverUser, PushoverToken, NtfyUser, NtfyPassword, NtfyToken, ZoneAlert) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$first, $last, $email, $timezone, $pushoverUser, $pushoverToken, $ntfyUser, $ntfyPassword, $ntfyToken, $zoneAlert]);
        }
        $inserted++;
    }
}

echo "Restore complete. Inserted: {$inserted}, Updated: {$updated}\n";
