<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class StringInitialStrategy extends AbstractStrategy
{
    public function __construct(string $strategyName = 'string_initial')
    {
        parent::__construct($strategyName);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        $value = trim((string) $originalValue);

        if ($value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 1, 'UTF-8') . '.';
        }

        if (preg_match('/^./us', $value, $matches) === 1) {
            return $matches[0] . '.';
        }

        return substr($value, 0, 1) . '.';
    }
}
