<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Identity;

final class Identity
{
    /**
     * @param list<int> $phoneCandidateIds
     */
    public function __construct(
        public readonly IdentityLevel $level,
        public readonly string $channel,
        public readonly ?int $clientId,
        public readonly string $phone = '',
        public readonly array $phoneCandidateIds = [],
    ) {
    }

    public function canUseAccountTools(): bool
    {
        return $this->clientId !== null
            && $this->clientId > 0
            && ($this->level === IdentityLevel::Linked || $this->level === IdentityLevel::Verified);
    }

    public function canViewBilling(): bool
    {
        return $this->canUseAccountTools() && $this->level === IdentityLevel::Verified;
    }
}
