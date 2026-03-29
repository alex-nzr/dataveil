<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class EmptyStringStrategy extends AbstractStrategy
{
    public function __construct(string $strategyName = "empty")
    {
        parent::__construct($strategyName);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        return "";
    }
}
