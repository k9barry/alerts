#!/usr/bin/env php
<?php
declare(strict_types=1);

use App\Scheduler\ConsoleApp;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

$app = ConsoleApp::build();

$command = 'run-scheduler';

$input = new ArrayInput([
        'command' => $command,
]);
$output = new ConsoleOutput();

exit($app->run($input, $output));
