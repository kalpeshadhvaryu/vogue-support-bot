<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Security;

/**
 * WhatsApp customer-care session: free-form replies are allowed for 24 hours
 * after the customer's last message. Outside that window, send a template.
 */
final class SessionWindow
{
    public const WHATSAPP_SECONDS = 86400;

    public static function isOpen(?int $lastCustomerMessageUnix, int $now, int $windowSeconds = self::WHATSAPP_SECONDS): bool
    {
        if ($lastCustomerMessageUnix === null || $lastCustomerMessageUnix <= 0 || $windowSeconds <= 0) {
            return false;
        }
        // A timestamp slightly ahead of our clock is still an open session.
        if ($lastCustomerMessageUnix > $now + 120) {
            return false;
        }
        $age = $now - $lastCustomerMessageUnix;
        if ($age < 0) {
            return true;
        }
        return $age < $windowSeconds;
    }
}
