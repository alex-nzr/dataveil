<?php

declare(strict_types=1);

namespace DataVeil\Tests\Unit\Serializer;

use DataVeil\Serializer\SerializerHandler;
use PHPUnit\Framework\TestCase;

class SerializerHandlerTest extends TestCase
{
    private SerializerHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new SerializerHandler();
    }

    public function testSerializeAndUnserialize(): void
    {
        $data = ['key' => 'value', 'num' => 123];
        
        $serialized = $this->handler->serialize($data);
        $unserialized = $this->handler->unserialize($serialized);
        
        $this->assertEquals($data, $unserialized);
    }

    public function testFindAndReplaceInSerialized(): void
    {
        $data = ['PHONE' => '+7 999 123-45-67', 'EMAIL' => 'test@example.com'];
        $serialized = serialize($data);

        $newSerialized = $this->handler->findAndReplaceInSerialized(
            $serialized,
            'PHONE',
            '+7 999 123-45-67',
            '+7 999 999-99-99'
        );

        $result = $this->handler->unserialize($newSerialized);
        
        $this->assertEquals('+7 999 999-99-99', $result['PHONE']);
        $this->assertEquals('test@example.com', $result['EMAIL']);
    }

    public function testUnserializeFailsOnInvalidData(): void
    {
        $this->expectException(\DataVeil\Exception\SerializationException::class);
        
        $this->handler->unserialize('invalid_serialized_data');
    }

    public function testDeserializeEmptyString(): void
    {
        $result = $this->handler->unserialize('b:0;');
        
        $this->assertFalse($result);
    }
}
