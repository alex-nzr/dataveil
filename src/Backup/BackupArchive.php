<?php

declare(strict_types=1);

namespace DataVeil\Backup;

class BackupArchive
{
    public function __construct(
        public readonly string $workDirectory,
        public readonly string $mainSqlPath,
        public readonly ?string $afterConnectPath,
        public readonly ?string $mainSqlArchivePath,
        public readonly ?string $afterConnectArchivePath,
        public readonly bool $isArchive,
    ) {
    }
}
