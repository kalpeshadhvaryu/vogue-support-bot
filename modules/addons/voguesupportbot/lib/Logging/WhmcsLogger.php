<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Logging;

final class WhmcsLogger
{
    /**
     * @param list<string> $secrets
     */
    public function moduleCall(string $action, mixed $request, mixed $response, array $secrets = []): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        logModuleCall('voguesupportbot', $action, $request, $response, '', $this->secrets($secrets));
    }

    public function activity(string $message): void
    {
        if (!function_exists('logActivity')) {
            return;
        }
        logActivity('Vogue Support Bot: ' . $this->clip($message));
    }

    /**
     * @param list<string> $secrets
     * @return list<string>
     */
    private function secrets(array $secrets): array
    {
        $clean = [];
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $clean[] = $secret;
            }
        }
        return $clean;
    }

    private function clip(string $message): string
    {
        if (strlen($message) <= 400) {
            return $message;
        }
        return substr($message, 0, 399) . '…';
    }
}
