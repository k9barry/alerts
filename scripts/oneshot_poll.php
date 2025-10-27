<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

$fetcher = new App\Service\AlertFetcher();
$count = $fetcher->fetchAndStoreIncoming();

fwrite(STDOUT, "One-shot poll complete. Stored/updated alerts: {$count}\n");
