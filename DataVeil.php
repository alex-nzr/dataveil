#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use DataVeil\Command\AnonymizeCommand;
use DataVeil\Command\TestDbConnectionCommand;
use DataVeil\Command\TestConfigurationCommand;
use Symfony\Component\Console\Application;

$application = new Application('DataVeil', '1.0.0');

$application->add(new AnonymizeCommand());
$application->add(new TestConfigurationCommand());
$application->add(new TestDbConnectionCommand());

$application->run();
