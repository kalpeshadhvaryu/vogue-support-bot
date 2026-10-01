<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Http;

use VogueHosting\SupportBot\Security\CorsPolicy;

final class SystemUrl
{
    public static function base(): string
    {
        $config = $GLOBALS['CONFIG']['SystemURL'] ?? '';
        return rtrim(is_string($config) ? $config : '', '/');
    }

    public static function origin(): ?string
    {
        return CorsPolicy::normalize(self::base());
    }

    public static function moduleBase(): string
    {
        return self::base() . '/modules/addons/voguesupportbot';
    }
}
