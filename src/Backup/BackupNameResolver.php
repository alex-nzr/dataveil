<?php

declare(strict_types=1);

namespace DataVeil\Backup;

use DataVeil\Exception\DataVeilException;

class BackupNameResolver
{
    public function resolveOutputPath(string $inputPath, ?string $outputPath): string
    {
        if ($outputPath !== null && $outputPath !== '') {
            return $outputPath;
        }

        $directory = dirname($inputPath);
        $basename = basename($inputPath);

        if (str_ends_with($basename, '.tar.gz')) {
            $base = substr($basename, 0, -7);

            return $directory . DIRECTORY_SEPARATOR . $this->anonymizedBaseName($base) . '.tar.gz';
        }

        if (str_ends_with($basename, '.sql')) {
            $base = substr($basename, 0, -4);

            return $directory . DIRECTORY_SEPARATOR . $this->anonymizedBaseName($base) . '.sql';
        }

        throw new DataVeilException('Supported backup formats: .sql, .tar.gz');
    }

    public function anonymizedSqlName(string $sqlName): string
    {
        if (!str_ends_with($sqlName, '.sql')) {
            throw new DataVeilException("Expected SQL file name: {$sqlName}");
        }

        $base = substr($sqlName, 0, -4);

        return $this->anonymizedBaseName($base) . '.sql';
    }

    private function anonymizedBaseName(string $baseName): string
    {
        if (str_contains($baseName, '.anonymized_')) {
            return $baseName;
        }

        if (preg_match('/^(?<portal>.+?)(?<suffix>_\d{8}_\d{6}_sql_.+)$/', $baseName, $matches) === 1) {
            return $matches['portal'] . '.anonymized' . $matches['suffix'];
        }

        return $baseName . '.anonymized';
    }
}
