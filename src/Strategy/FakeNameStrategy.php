<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class FakeNameStrategy extends AbstractStrategy
{
    /**
     * @var array<string>
     */
    private static array $firstNames = [
        'Иван', 'Алексей', 'Дмитрий', 'Сергей', 'Андрей', 'Максим', 'Артем', 'Михаил', 'Николай', 'Егор',
        'Владимир', 'Павел', 'Кирилл', 'Степан', 'Федор', 'Евгений', 'Антон', 'Виктор', 'Борис', 'Глеб',
        'Александр', 'Игорь', 'Олег', 'Виталий', 'Валентин', 'Юрий', 'Богдан', 'Тимофей', 'Георгий', 'Роман',
    ];

    public function __construct(string $strategyName = "name_fake")
    {
        parent::__construct($strategyName);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        if ($salt !== null) {
            $id = intval($salt);
        } elseif (is_numeric($originalValue)) {
            $id = $originalValue;
        } else {
            $id = crc32($originalValue);
        }

        return self::$firstNames[$id % count(self::$firstNames)];
    }
}
