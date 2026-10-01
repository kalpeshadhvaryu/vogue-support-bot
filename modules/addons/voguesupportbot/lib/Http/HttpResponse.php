<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $error,
    ) {
    }
}
