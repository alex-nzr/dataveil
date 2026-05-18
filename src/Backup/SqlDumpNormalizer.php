<?php

declare(strict_types=1);

namespace DataVeil\Backup;

use DataVeil\Exception\DataVeilException;

class SqlDumpNormalizer
{
    public function normalizeFile(string $path): void
    {
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new DataVeilException("Failed to read exported SQL dump: {$path}");
        }

        $normalized = $this->normalize($sql);

        if ($normalized !== $sql && file_put_contents($path, $normalized) === false) {
            throw new DataVeilException("Failed to write normalized SQL dump: {$path}");
        }
    }

    public function normalize(string $sql): string
    {
        $sql = preg_replace(
            '/^\s*\/\*!\d{5}\s+SET\s+@saved_cs_client\s*=.*?\*\/;\s*\R?/mi',
            '',
            $sql,
        ) ?? $sql;

        $sql = preg_replace(
            '/^\s*\/\*!\d{5}\s+SET\s+@OLD_[A-Z0-9_]+\s*=.*?\*\/;\s*\R?/mi',
            '',
            $sql,
        ) ?? $sql;

        return preg_replace(
            '/^\s*\/\*!\d{5}\s+SET\s+[A-Z0-9_]+\s*=\s*@(saved_cs_client|OLD_[A-Z0-9_]+)\s*\*\/;\s*\R?/mi',
            '',
            $sql,
        ) ?? $sql;
    }
}
