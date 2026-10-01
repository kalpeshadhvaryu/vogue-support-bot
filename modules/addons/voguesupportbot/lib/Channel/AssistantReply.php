<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Channel;

final class AssistantReply
{
    public function __construct(
        public readonly string $text,
        public readonly bool $handoff,
        public readonly ?string $ticketTid,
    ) {
    }
}
