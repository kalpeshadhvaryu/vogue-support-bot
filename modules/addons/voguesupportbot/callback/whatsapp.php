<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    VogueHosting\SupportBot\Channel\WhatsAppEndpoint::handle();
} catch (Throwable $exception) {
    if (function_exists('logActivity')) {
        logActivity('Vogue Support Bot: WhatsApp webhook failed: ' . $exception->getMessage());
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo '{"ok":false}';
}
