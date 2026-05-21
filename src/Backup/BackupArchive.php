<?php

declare(strict_types=1);

namespace DataVeil\Backup;

class BackupArchive
{
    public string $workDirectory;
    public string $mainSqlPath;
    public ?string $afterConnectPath;
    public ?string $mainSqlArchivePath;
    public ?string $afterConnectArchivePath;
    public bool $isArchive;

    public function __construct(
        string $workDirectory,
        string $mainSqlPath,
        ?string $afterConnectPath,
        ?string $mainSqlArchivePath,
        ?string $afterConnectArchivePath,
        bool $isArchive
    ) {
        $this->workDirectory = $workDirectory;
        $this->mainSqlPath = $mainSqlPath;
        $this->afterConnectPath = $afterConnectPath;
        $this->mainSqlArchivePath = $mainSqlArchivePath;
        $this->afterConnectArchivePath = $afterConnectArchivePath;
        $this->isArchive = $isArchive;
    }
}
