<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    VogueHosting\SupportBot\Channel\ChatEndpoint::website();
} catch (Throwable $exception) {
    if (function_exists('logActivity')) {
        logActivity('Vogue Support Bot: website chat failed: ' . $exception->getMessage());
    }
    VogueHosting\SupportBot\Http\JsonResponse::send(500, [
        'ok' => false,
        'error' => 'server_error',
        'reply' => 'Something went wrong. Please try again or open a support ticket.',
    ]);
}
