<?php

declare(strict_types=1);

namespace DataVeil\Backup;

use DataVeil\Exception\DataVeilException;

class TemporaryDatabaseGuard
{
    /**
     * @var array<string>
     */
    private array $dangerousNames = [
        'bitrix',
        'b24',
        'prod',
        'production',
        'sitemanager',
        'main',
        'default',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
    ];

    public function assertSafe(string $tempDatabase, ?string $configuredDatabase = null, bool $allowConfiguredDatabase = false): void
    {
        $normalized = strtolower(trim($tempDatabase));

        if ($normalized === '') {
            throw new DataVeilException('Temporary database name is required');
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $tempDatabase) !== 1) {
            throw new DataVeilException('Temporary database name may contain only letters, digits and underscore');
        }

        if (in_array($normalized, $this->dangerousNames, true)) {
            throw new DataVeilException("Unsafe temporary database name: {$tempDatabase}");
        }

        if (!$allowConfiguredDatabase && $configuredDatabase !== null && strtolower($configuredDatabase) === $normalized) {
            throw new DataVeilException('Temporary database must not match configured database');
        }

        if (!str_starts_with($normalized, 'dataveil_tmp_')) {
            throw new DataVeilException("Temporary database name must start with 'dataveil_tmp_'");
        }
    }
}
