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
                    } catch (\Throwable $e) {
                        LoggerFactory::get()->error('Scheduler tick error', ['error' => $e->getMessage()]);
                    }

                    if ((time() - $lastVacuum) >= $vacuumEvery) {
                        try {
                            \App\DB\Connection::get()->exec('VACUUM');
                            LoggerFactory::get()->info('Database vacuum complete (scheduled)');
                        } catch (\Throwable $e) {
                            LoggerFactory::get()->error('Vacuum error', ['error' => $e->getMessage()]);
                        }
                        $lastVacuum = time();
                    }

                    sleep($pollSecs);
                }
            }
        });

        return $app;
    }
}
