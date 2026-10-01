<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tools;

final class ToolExecution
{
    /**
     * @param array<string, mixed> $forModel
     */
    public function __construct(
        public readonly array $forModel,
        public readonly ?int $verifiedClientId = null,
        public readonly ?int $handoffTicketId = null,
        public readonly ?string $handoffTicketTid = null,
    ) {
    }
}
