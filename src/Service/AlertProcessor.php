<?php
namespace App\Service;

use App\Logging\LoggerFactory;
use App\Repository\AlertsRepository;
use App\Repository\UserRepository;

final class AlertProcessor
{
    private AlertsRepository $alerts;
    private UserRepository $users;
    private PushoverNotifier $notifier;

    public function __construct()
    {
        $this->alerts = new AlertsRepository();
        $this->users = new UserRepository();
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
        $users = $this->users->all();
        foreach ($pending as $p) {
            $same = json_decode($p['same_array'] ?? '[]', true) ?: [];
            $ugc = json_decode($p['ugc_array'] ?? '[]', true) ?: [];
            foreach ($users as $u) {
                $userSame = json_decode($u['same_array'] ?? '[]', true) ?: [];
                $userUgc = json_decode($u['ugc_array'] ?? '[]', true) ?: [];
                $match = array_intersect($same, $userSame) || array_intersect($ugc, $userUgc);
                if ($match) {
                    $this->notifier->notifyUser((string)$p['id'], $p['json'], $u);
                }
            }
            $this->alerts->deletePendingById((string)$p['id']);
        }
    }
}
