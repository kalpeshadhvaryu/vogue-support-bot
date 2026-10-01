<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Security;

final class RateLimitDecision
{
    /**
     * @param array{window_start: int, hits: int}|null $bucket
     * @return array{allowed: bool, window_start: int, hits: int}
     */
    public static function hit(?array $bucket, int $limit, int $windowSeconds, int $now): array
    {
        $limit = max(1, $limit);
        $windowSeconds = max(1, $windowSeconds);
        $expired = $bucket === null || ($now - $bucket['window_start']) >= $windowSeconds;
        if ($expired) {
            return [
                'allowed' => true,
                'window_start' => $now,
                'hits' => 1,
            ];
        }
        if ($bucket['hits'] >= $limit) {
            return [
                'allowed' => false,
                'window_start' => $bucket['window_start'],
                'hits' => $bucket['hits'],
            ];
        }
        return [
            'allowed' => true,
            'window_start' => $bucket['window_start'],
            'hits' => $bucket['hits'] + 1,
        ];
    }
}
