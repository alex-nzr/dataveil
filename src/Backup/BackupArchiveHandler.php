<?php

declare(strict_types=1);

namespace DataVeil\Backup;

use DataVeil\Exception\DataVeilException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BackupArchiveHandler
{
    private BackupNameResolver $nameResolver;

    public function __construct(?BackupNameResolver $nameResolver = null)
    {
        $this->nameResolver = $nameResolver ?? new BackupNameResolver();
    }

    public function unpack(string $inputPath, string $workDirectory): BackupArchive
    {
        if (str_ends_with($inputPath, '.sql')) {
            return new BackupArchive(
                $workDirectory,
                $inputPath,
                null,
                null,
                null,
                false,
            );
        }

        if (!str_ends_with($inputPath, '.tar.gz')) {
            throw new DataVeilException('Supported backup formats: .sql, .tar.gz');
        }

        $this->runTar(['tar', '-xzf', $inputPath, '-C', $workDirectory]);

        $sqlFiles = $this->findSqlFiles($workDirectory);
        $mainSql = null;
        $afterConnect = null;

        foreach ($sqlFiles as $sqlFile) {
            if (str_ends_with($sqlFile, '_after_connect.sql')) {
                $afterConnect = $sqlFile;
            } else {
                $mainSql = $sqlFile;
            }
        }

        if ($mainSql === null) {
            throw new DataVeilException('Main SQL dump not found in backup archive');
        }

        return new BackupArchive(
            $workDirectory,
            $mainSql,
            $afterConnect,
            $this->relativePath($workDirectory, $mainSql),
            $afterConnect === null ? null : $this->relativePath($workDirectory, $afterConnect),
            true,
        );
    }

    public function pack(BackupArchive $archive, string $exportedSqlPath, string $outputPath): void
    {
        if (!$archive->isArchive) {
            if (!copy($exportedSqlPath, $outputPath)) {
                throw new DataVeilException("Failed to write output SQL dump: {$outputPath}");
            }

            return;
        }

        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $stagingDirectory = $archive->workDirectory . DIRECTORY_SEPARATOR . 'output_archive';
        $mainArchivePath = $archive->mainSqlArchivePath ?? basename($archive->mainSqlPath);
        $renamedMainPath = $this->replaceBaseName(
            $mainArchivePath,
            $this->nameResolver->anonymizedSqlName(basename($mainArchivePath)),
        );

        $this->copyToStaging($exportedSqlPath, $stagingDirectory, $renamedMainPath);

        if ($archive->afterConnectPath !== null && $archive->afterConnectArchivePath !== null) {
            $renamedAfterConnect = $this->replaceBaseName(
                $archive->afterConnectArchivePath,
                $this->nameResolver->anonymizedSqlName(basename($archive->afterConnectArchivePath)),
            );
            $this->copyToStaging($archive->afterConnectPath, $stagingDirectory, $renamedAfterConnect);
        }

        $this->runTar(['tar', '-czf', $outputPath, '-C', $stagingDirectory, '.']);
    }

    /**
     * @return array<int, string>
     */
    private function findSqlFiles(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $files = [];

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.sql')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_NATURAL);

        return $files;
    }

    private function relativePath(string $baseDirectory, string $path): string
    {
        $relative = substr($path, strlen(rtrim($baseDirectory, DIRECTORY_SEPARATOR)) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function replaceBaseName(string $path, string $basename): string
    {
        $directory = dirname(str_replace('\\', '/', $path));

        return $directory === '.' ? $basename : $directory . '/' . $basename;
    }

    private function copyToStaging(string $sourcePath, string $stagingDirectory, string $archivePath): void
    {
        $targetPath = $stagingDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archivePath);
        $targetDirectory = dirname($targetPath);

        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new DataVeilException("Failed to create archive staging directory: {$targetDirectory}");
        }

        if (!copy($sourcePath, $targetPath)) {
            throw new DataVeilException("Failed to stage archive file: {$archivePath}");
        }
    }

    /**
     * @param array<int, string> $command
     */
    private function runTar(array $command): void
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new DataVeilException('Failed to start tar process');
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new DataVeilException('Failed to unpack tar.gz archive');
        }
    }
}
