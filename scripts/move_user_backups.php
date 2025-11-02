<?php
// Moves users_backup_*.json files from data/ into data/user_backup/ and keeps only the last 10
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Config;

$dataDir = dirname(Config::$dbPath);
$targetDir = $dataDir . '/users_backup';

if (!is_dir($dataDir)) {
    fwrite(STDERR, "Data directory does not exist: {$dataDir}\n");
    exit(1);
}

if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "Failed to create target directory: {$targetDir}\n");
        exit(1);
    }
}

$pattern = $dataDir . '/users_backup_*.json';
$files = glob($pattern);
if (!$files) {
    echo "No users_backup_*.json files found in {$dataDir}\n";
    exit(0);
}

// Move files into target dir
foreach ($files as $f) {
    $base = basename($f);
    $dest = $targetDir . '/' . $base;
    if (realpath($f) === realpath($dest)) continue;
    if (!@rename($f, $dest)) {
        // fallback to copy+unlink
        if (!@copy($f, $dest)) {
            fwrite(STDERR, "Failed to move {$f} to {$dest}\n");
            continue;
        }
        @unlink($f);
    }
    echo "Moved {$base} -> users_backup/\n";
}

// Prune leaving only the 10 most recent files (by mtime)
$all = glob($targetDir . '/users_backup_*.json');
usort($all, function($a,$b){ return filemtime($b) <=> filemtime($a); });
if (count($all) > 10) {
    $toDelete = array_slice($all, 10);
    foreach ($toDelete as $del) {
        if (@unlink($del)) echo "Pruned " . basename($del) . "\n";
    }
}

echo "Done. users_backup contains up to 10 recent backups.\n";
