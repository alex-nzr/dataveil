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
        $this->register(new EmptyStringStrategy());
    }

    public function register(StrategyInterface $strategy): void
    {
        $this->strategies[] = $strategy;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function getStrategy(string $strategyName, array $options = []): ?StrategyInterface
    {
        $configuredStrategy = $this->createConfiguredStrategy($strategyName, $options);

        if ($configuredStrategy !== null) {
            return $configuredStrategy;
        }

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($strategyName)) {
                return $strategy;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(
        string $strategyName,
        mixed $originalValue,
        ?string $salt = null,
        array $options = []
    ): string
    {
        $strategy = $this->getStrategy($strategyName, $options);

        if ($strategy === null) {
            throw new \InvalidArgumentException("Unknown strategy: {$strategyName}");
        }

        return $strategy->generate($originalValue, $salt);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createConfiguredStrategy(string $strategyName, array $options): ?StrategyInterface
    {
        if ($strategyName === 'string_random') {
            return new StringRandomStrategy(
                strategyName: $strategyName,
                options: $this->normalizeStringRandomOptions($options),
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string|int>
     */
    private function normalizeStringRandomOptions(array $options): array
    {
        $normalized = [];

        if (isset($options['prefix'])) {
            $normalized['prefix'] = (string) $options['prefix'];
        }

        if (isset($options['length'])) {
            $normalized['length'] = (int) $options['length'];
        }

        return $normalized;
    }
}
