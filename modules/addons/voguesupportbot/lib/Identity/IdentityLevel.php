<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Identity;

enum IdentityLevel: string
{
    case Anonymous = 'anonymous';
    case Linked = 'linked';
    case Verified = 'verified';
}
