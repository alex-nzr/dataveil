<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class FakeMiddlenameStrategy extends AbstractStrategy
{
    /**
     * @var array<string>
     */
    private static array $middleNames = [
        'Иванович', 'Алексеевич', 'Дмитриевич', 'Сергеевич', 'Андреевич',
        'Максимович', 'Михайлович', 'Николаевич', 'Павлович', 'Викторович',
        'Ивановна', 'Алексеевна', 'Дмитриевна', 'Сергеевна', 'Андреевна',
        'Максимовна', 'Михайловна', 'Николаевна', 'Павловна', 'Викторовна',
    ];

    public function __construct(string $strategyName = 'middlename_fake')
    {
        parent::__construct($strategyName);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        $id = $this->resolveId($originalValue, $salt);

        return self::$middleNames[$id % count(self::$middleNames)];
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
