<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Security;

final class ClientIp
{
    /**
     * @param array<string, mixed> $server
     */
    public static function resolve(array $server, bool $trustProxy): string
    {
        if ($trustProxy) {
            $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (self::isIp($first)) {
                    return $first;
                }
            }
        }
        $remote = $server['REMOTE_ADDR'] ?? '';
        return is_string($remote) && self::isIp($remote) ? $remote : '0.0.0.0';
    }

    private static function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }
}
