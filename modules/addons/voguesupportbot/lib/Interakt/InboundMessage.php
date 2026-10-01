<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Interakt;

/**
 * Parses Interakt's message_received webhook.
 * Delivery-status events (message_api_sent and the rest) return null.
 *
 * @see https://www.interakt.shop/resource-center/interakts-webhooks/
 */
final class InboundMessage
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $phone,
        public readonly string $text,
        public readonly string $contentType,
        public readonly ?int $receivedAtUnix,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        if (($payload['type'] ?? '') !== 'message_received') {
            return null;
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }
        $customer = $data['customer'] ?? null;
        $message = $data['message'] ?? null;
        if (!is_array($customer) || !is_array($message)) {
            return null;
        }
        $phone = $customer['channel_phone_number'] ?? '';
        $messageId = $message['id'] ?? '';
        if (!is_string($phone) || $phone === '' || !is_string($messageId) || $messageId === '') {
            return null;
        }
        $text = $message['message'] ?? '';
        $contentType = $message['message_content_type'] ?? '';
        return new self(
            $messageId,
            $phone,
            is_string($text) ? $text : '',
            is_string($contentType) ? $contentType : '',
            self::timestamp($message['received_at_utc'] ?? null),
        );
    }

    private static function timestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $unix = strtotime($value . ' UTC');
        return $unix === false ? null : $unix;
    }
}
