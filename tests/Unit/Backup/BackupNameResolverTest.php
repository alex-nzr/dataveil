<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Backup;

use DataVeil\Backup\BackupNameResolver;
use PHPUnit\Framework\TestCase;

class BackupNameResolverTest extends TestCase
{
    public function testResolvesBitrixArchiveOutputName(): void
    {
        $resolver = new BackupNameResolver();

        $output = $resolver->resolveOutputPath(
            'D:/backup/b24.test_20260508_182502_sql_jh2stc2v5jr8bnvm.tar.gz',
            null,
        );

        $this->assertSame(
            'D:/backup' . DIRECTORY_SEPARATOR . 'b24.test.anonymized_20260508_182502_sql_jh2stc2v5jr8bnvm.tar.gz',
            $output,
        );
    }

    public function testResolvesBitrixSqlOutputName(): void
    {
        $resolver = new BackupNameResolver();

        $output = $resolver->resolveOutputPath(
            'D:/backup/b24.test_20260508_182502_sql_jh2stc2v5jr8bnvm.sql',
            null,
        );

        $this->assertSame(
            'D:/backup' . DIRECTORY_SEPARATOR . 'b24.test.anonymized_20260508_182502_sql_jh2stc2v5jr8bnvm.sql',
            $output,
        );
    }

    public function testResolvesAfterConnectName(): void
    {
        $resolver = new BackupNameResolver();

        $this->assertSame(
            'b24.test.anonymized_20260508_182502_sql_jh2stc2v5jr8bnvm_after_connect.sql',
            $resolver->anonymizedSqlName('b24.test_20260508_182502_sql_jh2stc2v5jr8bnvm_after_connect.sql'),
        );
    }
}
