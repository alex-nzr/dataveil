<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

abstract class AbstractStrategy implements StrategyInterface
{
    protected string $strategyName;

    public function __construct(string $strategyName)
    {
        $this->strategyName = $strategyName;
    }

    abstract public function generate(mixed $originalValue, ?string $salt = null): string;
    
    public function supports(string $strategyName): bool
    {
        return $this->strategyName === $strategyName;
    }
}
