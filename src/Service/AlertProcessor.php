<?php
namespace App\Service;

use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;
use App\Config;

final class AlertProcessor
{
    private AlertsRepository $alerts;
    private PushoverNotifier $notifier;

    public function __construct()
    {
        $this->alerts = new AlertsRepository();
        $this->notifier = new PushoverNotifier();
    }

    public function diffAndQueue(): int
    {
        $n = $this->alerts->queuePendingForNew();
        LoggerFactory::get()->info('Queued new alerts into pending', ['count' => $n]);
        return $n;
    }

    public function processPending(): void
    {
        $pending = $this->alerts->getPending();
        foreach ($pending as $p) {
            // For now, send all pending alerts to the configured Pushover recipient.
            $this->notifier->notify((string)$p['id'], $p['json']);
            $this->alerts->deletePendingById((string)$p['id']);
        }
    }
}
