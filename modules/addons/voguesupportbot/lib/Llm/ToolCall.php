<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Llm;

final class ToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $argumentsJson,
    ) {
    }
}
