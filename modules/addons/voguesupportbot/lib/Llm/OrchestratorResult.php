<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Llm;

final class OrchestratorResult
{
    /**
     * @param list<array{name: string, content: string}> $toolTrace
     */
    public function __construct(
        public readonly string $text,
        public readonly int $tokens,
        public readonly ?int $verifiedClientId,
        public readonly ?int $handoffTicketId,
        public readonly ?string $handoffTicketTid,
        public readonly array $toolTrace,
        public readonly bool $failed,
    ) {
    }
}
