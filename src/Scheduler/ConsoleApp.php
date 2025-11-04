<?php
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
                $db = \App\DB\Connection::get();
                $db->exec('VACUUM');
                LoggerFactory::get()->info('Database vacuum complete');
                return Command::SUCCESS;
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
