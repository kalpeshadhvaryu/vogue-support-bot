<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Llm;

final class GrokCompletion
{
    /**
     * @param list<ToolCall> $toolCalls
     */
    public function __construct(
        public readonly ?string $content,
        public readonly array $toolCalls,
        public readonly int $totalTokens,
        public readonly ?string $error,
    ) {
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }
}
