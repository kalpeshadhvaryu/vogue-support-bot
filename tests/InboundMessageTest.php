<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Interakt\InboundMessage;

final class InboundMessageTest extends TestCase
{
    public function testParsesACustomerTextMessage(): void
    {
        $message = InboundMessage::fromPayload([
            'version' => '1.0',
            'type' => 'message_received',
            'data' => [
                'customer' => [
                    'id' => 'cust',
                    'channel_phone_number' => '917003705584',
                ],
                'message' => [
                    'id' => '60076f05-da52-4dd1-b813-36223c1eded7',
                    'message_content_type' => 'Text',
                    'message' => 'Thank you',
                    'received_at_utc' => '2022-06-03T05:57:57.359000',
                ],
            ],
        ]);
        self::assertNotNull($message);
        self::assertSame('917003705584', $message->phone);
        self::assertSame('Thank you', $message->text);
        self::assertSame('Text', $message->contentType);
        self::assertSame(strtotime('2022-06-03T05:57:57 UTC'), $message->receivedAtUnix);
    }

    public function testIgnoresDeliveryStatusEvents(): void
    {
        self::assertNull(InboundMessage::fromPayload([
            'type' => 'message_api_sent',
            'data' => ['customer' => ['channel_phone_number' => '9198'], 'message' => ['id' => '1']],
        ]));
        self::assertNull(InboundMessage::fromPayload(['type' => 'message_received', 'data' => []]));
    }
}
