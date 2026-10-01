<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tools;

final class ValidationResult
{
    /**
     * @param array<string, mixed> $arguments
     */
    private function __construct(
        public readonly bool $ok,
        public readonly string $error,
        public readonly array $arguments,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function success(array $arguments): self
    {
        return new self(true, '', $arguments);
    }

    public static function failure(string $error): self
    {
        return new self(false, $error, []);
    }
}
