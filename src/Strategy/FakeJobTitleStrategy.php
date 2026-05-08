<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class FakeJobTitleStrategy extends AbstractStrategy
{
    /**
     * @var array<string>
     */
    private static array $jobTitles = [
        'Менеджер',
        'Специалист',
        'Ведущий специалист',
        'Руководитель отдела',
        'Координатор',
        'Консультант',
        'Аналитик',
        'Администратор',
        'Директор проекта',
        'Эксперт',
    ];

    public function __construct(string $strategyName = 'job_title_fake')
    {
        parent::__construct($strategyName);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        $id = $this->resolveId($originalValue, $salt);

        return self::$jobTitles[$id % count(self::$jobTitles)];
    }

    private function resolveId(mixed $originalValue, ?string $salt): int
    {
        if ($salt !== null) {
            return abs((int) crc32($salt));
        }

        if (is_numeric($originalValue)) {
            return abs((int) $originalValue);
        }

        return abs((int) crc32((string) $originalValue));
    }
}
