<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Storage;

final class Conversation
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $accessTokenHash,
        public readonly string $channel,
        public readonly ?int $clientId,
        public readonly string $externalKey,
        public readonly string $identityLevel,
        public readonly string $handoffStatus,
        public readonly ?int $handoffTicketId,
        public readonly ?string $handoffTicketTid,
        public readonly ?string $lastCustomerAt,
        public readonly int $tokenUsage,
    ) {
    }

    public function tokenMatches(string $token): bool
    {
        if ($token === '' || $this->accessTokenHash === '') {
            return false;
        }
        return hash_equals($this->accessTokenHash, hash('sha256', $token));
    }
}
