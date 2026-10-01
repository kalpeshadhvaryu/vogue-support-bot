<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Security\ClientIp;
use VogueHosting\SupportBot\Security\CorsPolicy;
use VogueHosting\SupportBot\Security\RateLimitDecision;
use VogueHosting\SupportBot\Security\SessionCsrf;
use VogueHosting\SupportBot\Security\SessionWindow;

final class SecurityPolicyTest extends TestCase
{
    public function testSessionWindowBoundaries(): void
    {
        $start = 1_700_000_000;
        self::assertFalse(SessionWindow::isOpen(null, $start));
        self::assertTrue(SessionWindow::isOpen($start, $start + 86399));
        self::assertFalse(SessionWindow::isOpen($start, $start + 86400));
        self::assertTrue(SessionWindow::isOpen($start + 60, $start));
        self::assertFalse(SessionWindow::isOpen($start + 121, $start));
    }

    public function testCorsAllowListIsExact(): void
    {
        $allowed = ['https://VogueHosting.com', 'http://localhost:8080'];
        self::assertSame('https://voguehosting.com', CorsPolicy::match('https://voguehosting.com/', $allowed));
        self::assertSame('https://voguehosting.com', CorsPolicy::match('https://voguehosting.com:443', $allowed));
        self::assertSame('http://localhost:8080', CorsPolicy::match('http://localhost:8080', $allowed));
        self::assertNull(CorsPolicy::match('https://voguehosting.com/pricing', $allowed));
        self::assertNull(CorsPolicy::match('https://evil.example', $allowed));
        self::assertNull(CorsPolicy::match('https://*.voguehosting.com', ['https://*.voguehosting.com']));
        self::assertNull(CorsPolicy::match(null, $allowed));
        self::assertNull(CorsPolicy::normalize('javascript:alert(1)'));
    }

    public function testRateLimitResetsWithTheWindow(): void
    {
        $first = RateLimitDecision::hit(null, 2, 600, 1000);
        self::assertTrue($first['allowed']);
        $second = RateLimitDecision::hit($first, 2, 600, 1001);
        self::assertTrue($second['allowed']);
        self::assertSame(2, $second['hits']);
        $blocked = RateLimitDecision::hit($second, 2, 600, 1002);
        self::assertFalse($blocked['allowed']);
        self::assertSame(2, $blocked['hits']);
        $reset = RateLimitDecision::hit($blocked, 2, 600, 1600);
        self::assertTrue($reset['allowed']);
        self::assertSame(1, $reset['hits']);
    }

    public function testSessionCsrf(): void
    {
        $session = [];
        $token = SessionCsrf::issue($session, 'voguesupportbot_csrf');
        self::assertSame($token, SessionCsrf::issue($session, 'voguesupportbot_csrf'));
        self::assertTrue(SessionCsrf::verify($session, 'voguesupportbot_csrf', $token));
        self::assertFalse(SessionCsrf::verify($session, 'voguesupportbot_csrf', $token . 'x'));
        self::assertFalse(SessionCsrf::verify($session, 'missing', $token));
    }

    public function testClientIpIgnoresForwardedHeaderUnlessTrusted(): void
    {
        $server = [
            'REMOTE_ADDR' => '203.0.113.8',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.10, 203.0.113.8',
        ];
        self::assertSame('203.0.113.8', ClientIp::resolve($server, false));
        self::assertSame('198.51.100.10', ClientIp::resolve($server, true));
        self::assertSame('0.0.0.0', ClientIp::resolve([], false));
    }
}
