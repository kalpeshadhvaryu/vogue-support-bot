<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;
use VogueHosting\SupportBot\Knowledge\KnowledgeSelector;
use VogueHosting\SupportBot\Tests\Support\FakeGateway;
use VogueHosting\SupportBot\Tools\ToolCatalog;
use VogueHosting\SupportBot\Tools\WhmcsToolExecutor;

final class WhmcsToolExecutorTest extends TestCase
{
    public function testLinkedClientCannotReadInvoices(): void
    {
        $gateway = new FakeGateway();
        $result = $this->executor($gateway)->execute('list_invoices', [], new Identity(
            IdentityLevel::Linked,
            'whatsapp',
            7,
            '919876543210',
            [7],
        ));
        self::assertFalse($result->forModel['ok']);
        self::assertSame('verification_required', $result->forModel['error']);
        self::assertSame([], $gateway->calls);
    }

    public function testReplyIsLimitedToTheClientsOwnTicket(): void
    {
        $gateway = new FakeGateway();
        $gateway->tickets[5] = ['id' => 5, 'tid' => 'T-5', 'userid' => 99, 'status' => 'Open', 'subject' => 'Other'];
        $gateway->tickets[6] = ['id' => 6, 'tid' => 'T-6', 'userid' => 7, 'status' => 'Open', 'subject' => 'Mine'];
        $executor = $this->executor($gateway);
        $identity = new Identity(IdentityLevel::Linked, 'whatsapp', 7, '9198', [7]);
        $denied = $executor->execute('reply_ticket', ['ticket_id' => 5, 'message' => 'Hello'], $identity);
        self::assertSame('ticket_not_found', $denied->forModel['error']);
        $allowed = $executor->execute('reply_ticket', ['ticket_id' => 6, 'message' => 'Thanks'], $identity);
        self::assertTrue($allowed->forModel['ok']);
        self::assertSame(7, $gateway->calls[array_key_last($gateway->calls)]['args'][2]);
    }

    public function testAnonymousTicketIsNotAttachedToALookedUpClient(): void
    {
        $gateway = new FakeGateway();
        $result = $this->executor($gateway)->execute('open_ticket', [
            'subject' => 'New site',
            'message' => 'I want hosting',
            'name' => 'Asha Rao',
            'email' => 'asha@example.com',
        ], new Identity(IdentityLevel::Anonymous, 'website', null));
        self::assertTrue($result->forModel['ok']);
        $params = $gateway->calls[0]['args'][0];
        self::assertArrayNotHasKey('clientid', $params);
        self::assertSame('asha@example.com', $params['email']);
    }

    public function testLinkedTicketIgnoresASuppliedEmail(): void
    {
        $gateway = new FakeGateway();
        $this->executor($gateway)->execute('open_ticket', [
            'subject' => 'DNS',
            'message' => 'Check this',
            'email' => 'someoneelse@example.com',
            'name' => 'Not Me',
        ], new Identity(IdentityLevel::Verified, 'clientarea', 4));
        $params = $gateway->calls[0]['args'][0];
        self::assertSame(4, $params['clientid']);
        self::assertArrayNotHasKey('email', $params);
    }

    public function testVerifyIdentityDoesNotEchoTheEmail(): void
    {
        $gateway = new FakeGateway();
        $gateway->clients[7] = [
            'id' => 7,
            'email' => 'asha@example.com',
            'phonenumber' => '9876543210',
            'firstname' => 'Asha',
            'lastname' => 'Rao',
        ];
        $ok = $this->executor($gateway)->execute('verify_identity', [
            'method' => 'email',
            'value' => 'asha@example.com',
        ], new Identity(IdentityLevel::Linked, 'whatsapp', 7, '919876543210', [7]));
        self::assertTrue($ok->forModel['verified']);
        self::assertSame(7, $ok->verifiedClientId);
        self::assertArrayNotHasKey('email', $ok->forModel);

        $bad = $this->executor($gateway)->execute('verify_identity', [
            'method' => 'email',
            'value' => 'nope@example.com',
        ], new Identity(IdentityLevel::Linked, 'whatsapp', 7, '919876543210', [7]));
        self::assertNull($bad->verifiedClientId);
        self::assertSame('not_matched', $bad->forModel['error']);
    }

    public function testAnonymousHandoffOpensAnAdminTicketWithTheTranscript(): void
    {
        $gateway = new FakeGateway();
        $executor = new WhmcsToolExecutor(
            $gateway,
            new KnowledgeSelector(),
            [],
            3,
            [
                ['role' => 'user', 'content' => 'I need a person'],
                ['role' => 'assistant', 'content' => 'I can connect you'],
            ],
            null,
            null,
            true,
        );
        $result = $executor->execute('handoff_to_human', [
            'reason' => 'Customer asked for a person',
        ], new Identity(IdentityLevel::Anonymous, 'website', null));
        self::assertSame('ABC-100', $result->handoffTicketTid);
        $params = $gateway->calls[0]['args'][0];
        self::assertTrue($params['admin']);
        self::assertStringContainsString('I need a person', $params['message']);
        self::assertArrayNotHasKey('clientid', $params);
    }

    public function testCatalogHidesAccountToolsFromAnonymousVisitors(): void
    {
        $anonymous = ToolCatalog::namesFor(new Identity(IdentityLevel::Anonymous, 'website', null));
        self::assertNotContains('list_invoices', $anonymous);
        self::assertNotContains('list_services', $anonymous);
        self::assertNotContains('verify_identity', $anonymous);
        self::assertContains('search_knowledge', $anonymous);

        $linked = ToolCatalog::namesFor(new Identity(IdentityLevel::Linked, 'whatsapp', 7, '91', [7]));
        self::assertContains('list_services', $linked);
        self::assertContains('verify_identity', $linked);
        self::assertNotContains('list_invoices', $linked);

        $verified = ToolCatalog::openAiTools(new Identity(IdentityLevel::Verified, 'clientarea', 4));
        $names = array_map(static fn (array $tool): string => $tool['function']['name'], $verified);
        self::assertContains('list_invoices', $names);
        self::assertNotContains('verify_identity', $names);
        foreach ($verified as $tool) {
            self::assertSame('function', $tool['type']);
            $properties = $tool['function']['parameters']['properties'];
            if ($properties instanceof \stdClass) {
                $properties = (array) $properties;
            }
            self::assertArrayNotHasKey('client_id', $properties);
            self::assertArrayNotHasKey('clientid', $properties);
        }
    }

    private function executor(FakeGateway $gateway): WhmcsToolExecutor
    {
        return new WhmcsToolExecutor($gateway, new KnowledgeSelector(), [], 2, [], null, null, true);
    }
}
