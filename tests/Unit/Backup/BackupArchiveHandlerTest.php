<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Backup;

use DataVeil\Backup\BackupArchiveHandler;
use Phar;
use PharData;
use PHPUnit\Framework\TestCase;

class BackupArchiveHandlerTest extends TestCase
{
    public function testRepackagesBitrixArchiveWithAnonymizedNames(): void
    {
        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dataveil_archive_test_' . bin2hex(random_bytes(4));
        mkdir($baseDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bitrix' . DIRECTORY_SEPARATOR . 'backup', 0777, true);
        mkdir($baseDir . DIRECTORY_SEPARATOR . 'work', 0777, true);

        $prefix = 'b24.test_20260508_182502_sql_jh2stc2v5jr8bnvm';
        $mainPath = $baseDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bitrix' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . $prefix . '.sql';
        $afterPath = $baseDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bitrix' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . $prefix . '_after_connect.sql';
        file_put_contents($mainPath, 'SELECT 1;');
        file_put_contents($afterPath, "SET NAMES 'utf8mb4';");

        $tarPath = $baseDir . DIRECTORY_SEPARATOR . $prefix . '.tar';
        $tar = new PharData($tarPath);
        $tar->buildFromDirectory($baseDir . DIRECTORY_SEPARATOR . 'src');
        $tar->compress(Phar::GZ);
        unset($tar);
        unlink($tarPath);

        $archivePath = $tarPath . '.gz';
        $outputPath = $baseDir . DIRECTORY_SEPARATOR . 'output.tar.gz';
        $exportPath = $baseDir . DIRECTORY_SEPARATOR . 'export.sql';
        file_put_contents($exportPath, 'SELECT 2;');

        $handler = new BackupArchiveHandler();
        $archive = $handler->unpack($archivePath, $baseDir . DIRECTORY_SEPARATOR . 'work');
        $handler->pack($archive, $exportPath, $outputPath);

        $list = [];
        exec('tar -tf ' . escapeshellarg($outputPath), $list);

        $this->assertStringContainsString('.anonymized_', implode("\n", $list));

        $this->removeDirectory($baseDir);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
