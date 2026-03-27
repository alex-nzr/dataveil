<?php

declare(strict_types=1);

namespace DataVeil\Serializer;

use DataVeil\Exception\SerializationException;

class SerializerHandler
{
    public function unserialize(string $serialized): mixed
    {
        $result = @unserialize($serialized, ['allowed_classes' => false]);

        if ($result === false && $serialized !== 'b:0;') {
            throw new SerializationException(
                'Failed to unserialize data: ' . $this->getLastError()
            );
        }

        return $result;
    }

    /**
     * @param      mixed   $data
     * @return     string
     */
    public function serialize(mixed $data): string
    {
        return serialize($data);
    }

    public function findAndReplaceInSerialized(
        string $serialized,
        string $matchKey,
        string $matchValue,
        string $newValue
    ): string {
        $data = $this->unserialize($serialized);

        if (is_array($data)) {
            $data = $this->searchAndReplace($data, $matchKey, $matchValue, $newValue);
        } elseif (is_string($data) && $data === $matchValue) {
            $data = $newValue;
        }

        return $this->serialize($data);
    }

    /**
     * @param      array<mixed>   $data
     * @param      string  $matchKey
     * @param      string  $matchValue
     * @param      string  $newValue
     * @return     array<mixed>
     */
    private function searchAndReplace(array $data, string $matchKey, string $matchValue, string $newValue): array
    {
        foreach ($data as $key => &$value) {
            if ($key === $matchKey && $value === $matchValue) {
                $value = $newValue;
            } elseif (is_array($value)) {
                $value = $this->searchAndReplace($value, $matchKey, $matchValue, $newValue);
            }
        }

        return $data;
    }

    private function getLastError(): string
    {
        $error = error_get_last();

        return $error['message'] ?? 'Unknown error';
    }
}
