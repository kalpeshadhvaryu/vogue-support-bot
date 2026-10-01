<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Knowledge;

final class ScoredDocument
{
    public function __construct(
        public readonly KnowledgeDocument $document,
        public readonly int $score,
    ) {
    }
}
