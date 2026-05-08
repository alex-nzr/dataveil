<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Strategy;

use DataVeil\Strategy\StrategyManager;
use DataVeil\Strategy\StringRandomStrategy;
use PHPUnit\Framework\TestCase;

class StrategyManagerTest extends TestCase
{
    private StrategyManager $strategyManager;

    protected function setUp(): void
    {
        $this->strategyManager = new StrategyManager();
    }

    public function testFakeEmailStrategy(): void
    {
        $email = $this->strategyManager->generate("email_fake", "user123");

        $this->assertStringContainsString("@fake.local", $email);
        $this->assertStringContainsString("user_", $email);
    }

    public function testFakePhoneStrategy(): void
    {
        $phone = $this->strategyManager->generate("phone_fake", "123456");

        $this->assertStringStartsWith("+7", $phone);
        $this->assertMatchesRegularExpression('/^\+7\d{3}\d{7}$/', $phone);
    }

    public function testFakeNameStrategy(): void
    {
        $name = $this->strategyManager->generate("name_fake", "1");
        $this->assertNotEmpty($name);
    }

    public function testFakeLastnameStrategy(): void
    {
        $lastname = $this->strategyManager->generate("lastname_fake", "1");

        $this->assertNotEmpty($lastname);
    }

    public function testFakeMiddlenameStrategy(): void
    {
        $middlename = $this->strategyManager->generate("middlename_fake", "1");

        $this->assertNotEmpty($middlename);
    }

    public function testFakeCompanyStrategy(): void
    {
        $company = $this->strategyManager->generate("company_fake", "1");

        $this->assertStringStartsWith('ООО "', $company);
    }

    public function testStringRandomStrategy(): void
    {
        $strategy = new StringRandomStrategy(
            strategyName: "exactly8",
            options: ["prefix" => "usr_", "length" => 8],
        );
        $this->strategyManager->register($strategy);

        $result = $this->strategyManager->generate("exactly8", "123456");

        $this->assertStringStartsWith("usr_", $result);
        $this->assertEquals(12, strlen($result));
    }

    public function testStringRandomOptionsAreAppliedWhenGenerating(): void
    {
        $result = $this->strategyManager->generate(
            "string_random",
            "123456",
            options: ["prefix" => "user_", "length" => 10],
        );

        $this->assertStringStartsWith("user_", $result);
        $this->assertEquals(15, strlen($result));
    }

    public function testNumberFakeStrategy(): void
    {
        $result = $this->strategyManager->generate(
            "number_fake",
            "123456",
            options: ["min" => 10, "max" => 20],
        );

        $this->assertGreaterThanOrEqual(10, (int) $result);
        $this->assertLessThanOrEqual(20, (int) $result);
    }

    public function testAmountFakeStrategy(): void
    {
        $result = $this->strategyManager->generate(
            "amount_fake",
            "123456",
            options: ["min" => 1000, "max" => 2000, "decimals" => 2],
        );

        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $result);
        $this->assertGreaterThanOrEqual(1000, (float) $result);
        $this->assertLessThanOrEqual(2001, (float) $result);
    }

    public function testEmptyGeneration(): void
    {
        $empty = $this->strategyManager->generate("empty", "test");
        $this->assertEmpty($empty);
    }

    public function testDeterministicGeneration(): void
    {
        $email1 = $this->strategyManager->generate(
            "email_fake",
            "test",
            salt: "55",
        );
        $email2 = $this->strategyManager->generate(
            "email_fake",
            "test",
            salt: "55",
        );

        $this->assertEquals($email1, $email2);
    }

    public function testUnknownStrategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->strategyManager->generate("unknown_strategy", "test");
    }
}
