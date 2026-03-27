<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class FakeEmailStrategy extends AbstractStrategy
{
    public function __construct(string $strategyName = "email_fake")
    {
        parent::__construct($strategyName);
    }

    public function generate(mixed $originalValue, ?string $salt = null): string
    {
        if ($salt !== null) {
            $id = $salt;
        } elseif (is_numeric($originalValue)) {
            $id = $originalValue;
        } else {
            $id = crc32($originalValue);
        }

        return "user_{$id}@fake.local";
    }
}
