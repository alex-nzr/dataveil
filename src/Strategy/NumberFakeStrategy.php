<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class NumberFakeStrategy extends AbstractStrategy
{
    private int $min;
    private int $max;
    private int $decimals;
    private bool $preserveSign;

    /**
     * @param array<string, string|int|bool> $options
     */
    public function __construct(string $strategyName = 'number_fake', array $options = [])
    {
        parent::__construct($strategyName);

        $this->min = isset($options['min']) ? (int) $options['min'] : 1;
        $this->max = isset($options['max']) ? (int) $options['max'] : 100000;
        $this->decimals = isset($options['decimals']) ? max(0, (int) $options['decimals']) : 0;
        $this->preserveSign = isset($options['preserve_sign']) && (bool) $options['preserve_sign'];

        if ($this->max < $this->min) {
            [$this->min, $this->max] = [$this->max, $this->min];
        }
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        $id = $this->resolveId($originalValue, $salt);
        $range = max(1, $this->max - $this->min + 1);
        $integerPart = $this->min + ($id % $range);
        $fractionPart = 0.0;

        if ($this->decimals > 0) {
            $fractionRange = 10 ** $this->decimals;
            $fractionSeed = intdiv($id, $range) % $fractionRange;
            $fractionPart = $fractionSeed / $fractionRange;
        }

        $value = $integerPart + $fractionPart;

        if ($this->preserveSign && is_numeric($originalValue) && (float) $originalValue < 0) {
            $value *= -1;
        }

        if ($this->decimals > 0) {
            return number_format($value, $this->decimals, '.', '');
        }

        return (string) (int) round($value);
    }

    private function resolveId(mixed $originalValue, ?string $salt): int
    {
        if ($salt !== null) {
            return abs((int) crc32($salt));
        }

        if (is_string($originalValue) && $originalValue !== '') {
            return abs((int) crc32($originalValue));
        }

        if (is_numeric($originalValue)) {
            return abs((int) crc32((string) $originalValue));
        }

        return abs((int) crc32('number_fake'));
    }
}
