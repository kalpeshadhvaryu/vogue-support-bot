<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Knowledge;

final class KnowledgeDocument
{
    public function __construct(
        public readonly string $source,
        public readonly string $id,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url = '',
    ) {
    }
}
