<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Security;

/**
 * Interakt signs the raw request body with HMAC-SHA256 and sends
 * "sha256=" plus the hex digest in the Interakt-Signature header.
 * @see https://www.interakt.shop/resource-center/interakts-webhooks/
 */
final class WebhookSignature
{
    public static function sign(string $secret, string $payload): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $secret, string $payload, string $header): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $expected = self::sign($secret, $payload);
        if (strlen($expected) !== strlen($header)) {
            return false;
        }
        return hash_equals($expected, $header);
    }
}
