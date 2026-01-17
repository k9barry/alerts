<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Config;
use App\Logging\LoggerFactory;
use App\Service\AlertFetcher;
use App\Service\AlertProcessor;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ConsoleApp builder
 *
 * Provides the Symfony Console application and registers internal commands used by
 * the scheduler and maintenance tasks.
 */
final class ConsoleApp
{
    public static function build(): Application
    {
        $app = new Application('alerts', Config::$appVersion);

        $app->add(new class('poll') extends Command {
            protected static $defaultName = 'poll';
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $fetcher = new AlertFetcher();
                $processor = new AlertProcessor();
                $fetcher->fetchAndStoreIncoming();
                $processor->diffAndQueue();
                $processor->processPending();
                return Command::SUCCESS;
            }
        });

        $app->add(new class('vacuum') extends Command {
            protected static $defaultName = 'vacuum';
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                // VACUUM reclaims disk space from the entire SQLite database
                // This optimizes the database file and compacts freed space
                $db = \App\DB\Connection::get();
                $db->exec('VACUUM');
                LoggerFactory::get()->info('Database vacuum complete');
                return Command::SUCCESS;
            }
        });

        $app->add(new class('cleanup-old-sent-alerts') extends Command {
            protected static $defaultName = 'cleanup-old-sent-alerts';
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                // Cleanup sent_alerts records older than 30 days
                $retentionDays = 30;
                try {
                    $db = \App\DB\Connection::get();
                    $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
                    
                    // Get count of records to be deleted
                    $stmt = $db->prepare("SELECT COUNT(*) FROM sent_alerts WHERE notified_at < :cutoff");
                    $stmt->execute([':cutoff' => $cutoffDate]);
                    $count = $stmt->fetchColumn() ?: 0;
                    unset($stmt);
                    
                    if ($count === 0) {
                        LoggerFactory::get()->info('No old sent_alerts records to cleanup', [
                            'retention_days' => $retentionDays,
                            'cutoff_date' => $cutoffDate
                        ]);
                        return Command::SUCCESS;
                    }
                    
                    LoggerFactory::get()->info('Starting cleanup of old sent_alerts records', [
                        'retention_days' => $retentionDays,
                        'cutoff_date' => $cutoffDate,
                        'records_to_delete' => $count
                    ]);
                    
                    // Delete old records
                    $db->beginTransaction();
                    try {
                        $stmt = $db->prepare('DELETE FROM sent_alerts WHERE notified_at < :cutoff');
                        $stmt->execute([':cutoff' => $cutoffDate]);
                        $deletedCount = $stmt->rowCount();
                        $db->commit();
                    } catch (\Throwable $e) {
                        $db->rollBack();
                        throw $e;
                    }
                    
                    LoggerFactory::get()->info('Deleted old sent_alerts records', [
                        'records_deleted' => $deletedCount,
                        'retention_days' => $retentionDays
                    ]);
                    
                    return Command::SUCCESS;
                } catch (\Throwable $e) {
                    LoggerFactory::get()->error('cleanup-old-sent-alerts error', [
                        'error' => $e->getMessage()
                    ]);
                    return Command::FAILURE;
                }
            }
        });

        $app->add(new class('run-scheduler') extends Command {
            protected static $defaultName = 'run-scheduler';
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $fetcher = new AlertFetcher();
                $processor = new AlertProcessor();

                $pollSecs = max(60, Config::$pollMinutes * 60);
                $vacuumEvery = max(1, Config::$vacuumHours) * 3600;
                $lastVacuum = time();

                while (true) {
                    try {
                        $fetcher->fetchAndStoreIncoming();
                        $processor->diffAndQueue();
                        $processor->processPending();
                        // Replace active with incoming after processing
                        (new \App\Repository\AlertsRepository())->replaceActiveWithIncoming();
                    } catch (\PDOException $e) {
                        // Log database errors with more detail
                        LoggerFactory::get()->error('Scheduler database error', [
                            'error' => $e->getMessage(),
                            'code' => $e->getCode(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    } catch (\Throwable $e) {
                        LoggerFactory::get()->error('Scheduler tick error', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }

                    if ((time() - $lastVacuum) >= $vacuumEvery) {
                        // Run cleanup of old sent_alerts before VACUUM
                        try {
                            $app = $this->getApplication();
                            if ($app) {
                                $cmd = $app->find('cleanup-old-sent-alerts');
                                if ($cmd) {
                                    $cmd->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output);
                                }
                            }
                        } catch (\Throwable $e) {
                            LoggerFactory::get()->error('cleanup-old-sent-alerts error', ['error' => $e->getMessage()]);
                        }
                        
                        try {
                            \App\DB\Connection::get()->exec('VACUUM');
                            LoggerFactory::get()->info('Database vacuum complete (scheduled)');
                        } catch (\Throwable $e) {
                            LoggerFactory::get()->error('Vacuum error', ['error' => $e->getMessage()]);
                        }
                        $lastVacuum = time();
                        // Run user backup rotation alongside scheduled maintenance
                        try {
                            $app = $this->getApplication();
                            if ($app) {
                                $cmd = $app->find('rotate-user-backups');
                                if ($cmd) {
                                    $cmd->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output);
                                }
                            }
                        } catch (\Throwable $e) {
                            LoggerFactory::get()->error('rotate-user-backups error', ['error' => $e->getMessage()]);
                        }
                    }

                    sleep($pollSecs);
                }
            }
        });

        // Rotate user backups: move into data/users_backup and prune to the 10 most recent files
        $app->add(new class('rotate-user-backups') extends Command {
            protected static $defaultName = 'rotate-user-backups';
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                try {
                    $dbPath = \App\Config::$dbPath;
                    $dataDir = dirname($dbPath);
                    $targetDir = $dataDir . '/users_backup';
                    if (!is_dir($dataDir)) {
                        $output->writeln("Data directory does not exist: {$dataDir}");
                        return Command::FAILURE;
                    }
                    if (!is_dir($targetDir)) {
                        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                            $output->writeln("Failed to create target directory: {$targetDir}");
                            return Command::FAILURE;
                        }
                    }
                    $pattern = $dataDir . '/users_backup_*.json';
                    $files = glob($pattern);
                    if (!$files) {
                        $output->writeln("No users_backup_*.json files found in {$dataDir}");
                        return Command::SUCCESS;
                    }
                    foreach ($files as $f) {
                        $base = basename($f);
                        $dest = $targetDir . '/' . $base;
                        if (realpath($f) === realpath($dest)) continue;
                        if (!@rename($f, $dest)) {
                            if (!@copy($f, $dest)) {
                                $output->writeln("Failed to move {$f} to {$dest}");
                                continue;
                            }
                            @unlink($f);
                        }
                        $output->writeln("Moved {$base} -> users_backup/");
                    }
                    $all = glob($targetDir . '/users_backup_*.json');
                    usort($all, function($a,$b){ return filemtime($b) <=> filemtime($a); });
                    if (count($all) > 10) {
                        $toDelete = array_slice($all, 10);
                        foreach ($toDelete as $del) {
                            if (@unlink($del)) $output->writeln("Pruned " . basename($del));
                        }
                    }
                    $output->writeln('Done. users_backup contains up to 10 recent backups.');
                    return Command::SUCCESS;
                } catch (\Throwable $e) {
                    $output->writeln('rotate-user-backups error: ' . $e->getMessage());
                    return Command::FAILURE;
                }
            }
        });

        return $app;
    }
}
