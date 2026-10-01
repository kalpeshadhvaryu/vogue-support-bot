<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Http;

final class JsonResponse
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function send(int $status, array $payload, ?string $corsOrigin = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        self::cors($corsOrigin);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}';
    }

    public static function empty(int $status, ?string $corsOrigin = null): void
    {
        http_response_code($status);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        self::cors($corsOrigin);
    }

    private static function cors(?string $corsOrigin): void
    {
        if ($corsOrigin === null) {
            return;
        }
        header('Access-Control-Allow-Origin: ' . $corsOrigin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }
}
