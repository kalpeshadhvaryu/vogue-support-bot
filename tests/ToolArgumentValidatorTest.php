<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;
use VogueHosting\SupportBot\Tools\ToolArgumentValidator;

final class ToolArgumentValidatorTest extends TestCase
{
    private ToolArgumentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ToolArgumentValidator();
    }

    public function testRejectsClientIdEvenWhenTheToolIsAllowed(): void
    {
        $result = $this->validator->validate('list_services', ['clientid' => 4], $this->verified());
        self::assertFalse($result->ok);
        self::assertSame('forbidden_argument', $result->error);
    }

    public function testRejectsUnknownToolsAndUnexpectedFields(): void
    {
        self::assertSame('unknown_tool', $this->validator->validate('drop_database', [], $this->verified())->error);
        $extra = $this->validator->validate('list_domains', ['include_secrets' => 'yes'], $this->verified());
        self::assertSame('unexpected_argument', $extra->error);
    }

    public function testBillingToolRequiresVerifiedIdentity(): void
    {
        $linked = new Identity(IdentityLevel::Linked, 'whatsapp', 7, '919876543210', [7]);
        $denied = $this->validator->validate('list_invoices', [], $linked);
        self::assertSame('tool_not_permitted', $denied->error);

        $allowed = $this->validator->validate('list_invoices', ['status' => 'Unpaid'], $this->verified());
        self::assertTrue($allowed->ok);
        self::assertSame('Unpaid', $allowed->arguments['status']);
    }

    public function testAnonymousOpenTicketRequiresNameAndEmail(): void
    {
        $anonymous = new Identity(IdentityLevel::Anonymous, 'website', null);
        $missing = $this->validator->validate('open_ticket', [
            'subject' => 'Need help',
            'message' => 'My site is down',
        ], $anonymous);
        self::assertSame('anonymous_contact_required', $missing->error);

        $ok = $this->validator->validate('open_ticket', [
            'subject' => 'Need help',
            'message' => 'My site is down',
            'name' => 'Asha Rao',
            'email' => 'asha@example.com',
        ], $anonymous);
        self::assertTrue($ok->ok);
    }

    public function testVerifiedOpenTicketDoesNotNeedContactFields(): void
    {
        $result = $this->validator->validate('open_ticket', [
            'subject' => 'DNS change',
            'message' => 'Please check the nameservers.',
        ], $this->verified());
        self::assertTrue($result->ok);
    }

    public function testTicketIdAndLimits(): void
    {
        $ok = $this->validator->validate('reply_ticket', [
            'ticket_id' => '4821',
            'message' => 'Following up on this.',
        ], $this->verified());
        self::assertTrue($ok->ok);
        self::assertSame(4821, $ok->arguments['ticket_id']);

        $zero = $this->validator->validate('reply_ticket', [
            'ticket_id' => 0,
            'message' => 'Nope',
        ], $this->verified());
        self::assertSame('invalid_argument', $zero->error);

        $limit = $this->validator->validate('list_tickets', ['limit' => 100], $this->verified());
        self::assertSame('invalid_argument', $limit->error);
    }

    public function testVerifyIdentityShapes(): void
    {
        $linked = new Identity(IdentityLevel::Linked, 'whatsapp', 7, '919876543210', [7]);
        $badDigits = $this->validator->validate('verify_identity', [
            'method' => 'invoice_last4',
            'value' => '12',
        ], $linked);
        self::assertSame('invalid_argument', $badDigits->error);

        $badEmail = $this->validator->validate('verify_identity', [
            'method' => 'email',
            'value' => 'not-an-email',
        ], $linked);
        self::assertSame('invalid_argument', $badEmail->error);

        $good = $this->validator->validate('verify_identity', [
            'method' => 'invoice_last4',
            'value' => '4321',
        ], $linked);
        self::assertTrue($good->ok);

        $website = $this->validator->validate('verify_identity', [
            'method' => 'email',
            'value' => 'asha@example.com',
        ], new Identity(IdentityLevel::Anonymous, 'website', null));
        self::assertSame('tool_not_permitted', $website->error);
    }

    private function verified(): Identity
    {
        return new Identity(IdentityLevel::Verified, 'clientarea', 4);
    }
}
