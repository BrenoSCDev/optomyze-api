<?php

namespace App\Services\Ai\Dto;

class AiToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {
    }
}
