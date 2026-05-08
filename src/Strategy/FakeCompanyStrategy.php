<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class FakeCompanyStrategy extends AbstractStrategy
{
    /**
     * @var array<string>
     */
    private static array $prefixes = [
        'Альфа', 'Вектор', 'Гранит', 'Диалог', 'Импульс',
        'Контур', 'Меридиан', 'Орион', 'Профит', 'Сфера',
    ];

    /**
     * @var array<string>
     */
    private static array $suffixes = [
        'Трейд', 'Сервис', 'Групп', 'Логистик', 'Проект',
        'Консалт', 'Техно', 'Инвест', 'Партнер', 'Ресурс',
    ];

    public function __construct(string $strategyName = 'company_fake')
    {
        parent::__construct($strategyName);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        $id = $this->resolveId($originalValue, $salt);
        $prefix = self::$prefixes[$id % count(self::$prefixes)];
        $suffix = self::$suffixes[(int) floor($id / count(self::$prefixes)) % count(self::$suffixes)];

        return sprintf('ООО "%s %s"', $prefix, $suffix);
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
