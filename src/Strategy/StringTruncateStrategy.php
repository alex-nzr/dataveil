<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class StringTruncateStrategy extends AbstractStrategy
{
    private int $length;

    public function __construct(string $strategyName = 'string_truncate_4', int $length = 4)
    {
        parent::__construct($strategyName);
        $this->length = $length;
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        $value = (string) $originalValue;

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $this->length, 'UTF-8');
        }

        preg_match_all('/./us', $value, $matches);

        return implode('', array_slice($matches[0], 0, $this->length));
    }
}
