<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Backup;

use DataVeil\Backup\TemporaryDatabaseGuard;
use DataVeil\Exception\DataVeilException;
use PHPUnit\Framework\TestCase;

class TemporaryDatabaseGuardTest extends TestCase
{
    public function testAllowsSafeTemporaryDatabase(): void
    {
        $guard = new TemporaryDatabaseGuard();
        $guard->assertSafe('dataveil_tmp_backup_20260508', 'bitrix_24_test');

        $this->addToAssertionCount(1);
    }

    public function testRejectsProductionLikeName(): void
    {
        $this->expectException(DataVeilException::class);

        (new TemporaryDatabaseGuard())->assertSafe('production', 'bitrix_24_test');
    }

    public function testRejectsConfiguredDatabaseName(): void
    {
        $this->expectException(DataVeilException::class);

        (new TemporaryDatabaseGuard())->assertSafe('dataveil_tmp_backup', 'dataveil_tmp_backup');
    }

    public function testRejectsNameWithoutSafePrefix(): void
    {
        $this->expectException(DataVeilException::class);

        (new TemporaryDatabaseGuard())->assertSafe('client_backup', 'bitrix_24_test');
    }
}
