<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Storage;

final class WebhookEventStore
{
    public function claim(string $eventId): bool
    {
        $eventId = substr($eventId, 0, 80);
        if ($eventId === '') {
            return false;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
        \WHMCS\Database\Capsule::table(Schema::WEBHOOK_EVENTS)->where('received_at', '<', $cutoff)->delete();
        try {
            \WHMCS\Database\Capsule::table(Schema::WEBHOOK_EVENTS)->insert([
                'event_id' => $eventId,
                'received_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
