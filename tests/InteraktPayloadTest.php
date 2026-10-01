<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Interakt\InteraktPayload;

final class InteraktPayloadTest extends TestCase
{
    public function testTemplateMatchesThePublishedShape(): void
    {
        $payload = InteraktPayload::template('+91', '9999999999', 'support_reply', 'en', 'Hello', 'vsb:abc');
        self::assertSame('+91', $payload['countryCode']);
        self::assertSame('9999999999', $payload['phoneNumber']);
        self::assertSame('Template', $payload['type']);
        self::assertSame('support_reply', $payload['template']['name']);
        self::assertSame('en', $payload['template']['languageCode']);
        self::assertSame(['Hello'], $payload['template']['bodyValues']);
    }

    public function testSessionTextUsesTheMessageEndpointFields(): void
    {
        $payload = InteraktPayload::sessionText('+91', '9999999999', 'Hello', 'vsb:abc');
        self::assertSame('Text', $payload['type']);
        self::assertSame('Hello', $payload['data']['message']);
        self::assertSame('+91', $payload['countryCode']);
        self::assertArrayNotHasKey('template', $payload);
    }
}
