<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Security;

final class SessionCsrf
{
    /**
     * @param array<string, mixed> $session
     */
    public static function issue(array &$session, string $key): string
    {
        $current = $session[$key] ?? null;
        if (!is_string($current) || strlen($current) < 32) {
            $session[$key] = bin2hex(random_bytes(32));
        }
        return $session[$key];
    }

    /**
     * @param array<string, mixed> $session
     */
    public static function verify(array $session, string $key, string $provided): bool
    {
        $expected = $session[$key] ?? null;
        if (!is_string($expected) || $expected === '' || $provided === '') {
            return false;
        }
        if (strlen($expected) !== strlen($provided)) {
            return false;
        }
        return hash_equals($expected, $provided);
    }
}
