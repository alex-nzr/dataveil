<?php

declare(strict_types=1);

namespace DataVeil\Strategy;

class StrategyManager
{
    /**
     * @var array<StrategyInterface>
     */
    private array $strategies = [];

    public function __construct()
    {
        $this->register(new FakeEmailStrategy());
        $this->register(new FakePhoneStrategy());
        $this->register(new FakeNameStrategy());
        $this->register(new FakeLastnameStrategy());
        $this->register(new StringRandomStrategy());
    }

    public function register(StrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }

    public function getStrategy(string $strategyName): ?StrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($strategyName)) {
                return $strategy;
            }
        }

        return null;
    }

    public function generate(string $strategyName, mixed $originalValue, ?string $salt = null): string
    {
        $strategy = $this->getStrategy($strategyName);

        if ($strategy === null) {
            throw new \InvalidArgumentException("Unknown strategy: {$strategyName}");
        }

        return $strategy->generate($originalValue, $salt);
    }
}
