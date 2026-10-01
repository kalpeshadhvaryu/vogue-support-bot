<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Security;

/**
 * Exact origin allow-list. Wildcards are rejected on purpose.
 */
final class CorsPolicy
{
    /**
     * @param list<string> $allowedOrigins
     */
    public static function match(?string $origin, array $allowedOrigins): ?string
    {
        if ($origin === null || $origin === '' || strtolower($origin) === 'null') {
            return null;
        }
        $normalized = self::normalize($origin);
        if ($normalized === null) {
            return null;
        }
        foreach ($allowedOrigins as $allowed) {
            $candidate = self::normalize($allowed);
            if ($candidate !== null && hash_equals($candidate, $normalized)) {
                return $candidate;
            }
        }
        return null;
    }

    public static function normalize(string $origin): ?string
    {
        $origin = trim($origin);
        if ($origin === '' || str_contains($origin, '*')) {
            return null;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $port = '';
        if (isset($parts['port'])) {
            $portNumber = (int) $parts['port'];
            $isDefault = ($scheme === 'https' && $portNumber === 443) || ($scheme === 'http' && $portNumber === 80);
            if (!$isDefault) {
                $port = ':' . $portNumber;
            }
        }
        return $scheme . '://' . $host . $port;
    }
}
