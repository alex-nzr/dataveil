<?php

declare(strict_types=1);

use DataVeil\Command\AnonymizeCommand;
use DataVeil\Command\TestDbConnectionCommand;
use DataVeil\Command\TestConfigurationCommand;
use PHPUnit\Framework\TestCase;

class CommandTest extends TestCase
{
    public function testCommandExists(): void
    {
        $command = new AnonymizeCommand();
        $this->assertInstanceOf(AnonymizeCommand::class, $command);
    }

    public function testTestConfigurationCommandExists(): void
    {
        $command = new TestConfigurationCommand();
        $this->assertInstanceOf(TestConfigurationCommand::class, $command);
    }

    public function testTestDbConnectionCommandExists(): void
    {
        $command = new TestDbConnectionCommand();
        $this->assertInstanceOf(TestDbConnectionCommand::class, $command);
    }
}
