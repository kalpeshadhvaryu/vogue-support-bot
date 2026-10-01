<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Security\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    public function testAcceptsTheDocumentedHexPrefix(): void
    {
        $secret = 'whsec_test';
        $payload = '{"type":"message_received","data":{"foo":1}}';
        $header = WebhookSignature::sign($secret, $payload);
        self::assertStringStartsWith('sha256=', $header);
        self::assertTrue(WebhookSignature::verify($secret, $payload, $header));
    }

    public function testRejectsTamperingWrongSecretAndMissingPrefix(): void
    {
        $secret = 'whsec_test';
        $payload = '{"type":"message_received"}';
        $header = WebhookSignature::sign($secret, $payload);
        self::assertFalse(WebhookSignature::verify($secret, $payload . ' ', $header));
        self::assertFalse(WebhookSignature::verify('other', $payload, $header));
        self::assertFalse(WebhookSignature::verify($secret, $payload, substr($header, 7)));
        self::assertFalse(WebhookSignature::verify('', $payload, $header));
        self::assertFalse(WebhookSignature::verify($secret, $payload, ''));
    }
}
