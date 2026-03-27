<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

interface StrategyInterface
{
    public function __construct(string $strategyName);
    public function generate(mixed $originalValue, ?string $salt = null): string;
    public function supports(string $strategyName): bool;
}
