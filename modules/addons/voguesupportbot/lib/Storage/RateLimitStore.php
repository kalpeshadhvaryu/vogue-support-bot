<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Storage;

use VogueHosting\SupportBot\Security\RateLimitDecision;

final class RateLimitStore
{
    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $key = substr($key, 0, 128);
        $now = time();
        $row = \WHMCS\Database\Capsule::table(Schema::RATE_LIMITS)->where('bucket_key', $key)->first();
        $bucket = $row === null ? null : [
            'window_start' => (int) $row->window_start,
            'hits' => (int) $row->hits,
        ];
        $decision = RateLimitDecision::hit($bucket, $limit, $windowSeconds, $now);
        \WHMCS\Database\Capsule::table(Schema::RATE_LIMITS)->updateOrInsert(
            ['bucket_key' => $key],
            ['window_start' => $decision['window_start'], 'hits' => $decision['hits']],
        );
        return $decision['allowed'];
    }
}
