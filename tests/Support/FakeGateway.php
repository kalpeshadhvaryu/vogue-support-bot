<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests\Support;

use RuntimeException;
use VogueHosting\SupportBot\Knowledge\KnowledgeDocument;
use VogueHosting\SupportBot\Whmcs\WhmcsGateway;

final class FakeGateway implements WhmcsGateway
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    /** @var array<int, array{id: int, email: string, phonenumber: string, firstname: string, lastname: string}> */
    public array $clients = [];

    /** @var array<int, list<array<string, mixed>>> */
    public array $invoices = [];

    /** @var array<int, array{id: int, tid: string, userid: int, status: string, subject: string}> */
    public array $tickets = [];

    public function knowledgeDocuments(): array
    {
        return [new KnowledgeDocument('kb', '1', 'Reset a password', 'Use the client area reset form.', '')];
    }

    public function findClientsByPhone(string $phone): array
    {
        $this->calls[] = ['method' => 'findClientsByPhone', 'args' => [$phone]];
        return [];
    }

    public function getClient(int $clientId): ?array
    {
        $this->calls[] = ['method' => 'getClient', 'args' => [$clientId]];
        return $this->clients[$clientId] ?? null;
    }

    public function listServices(int $clientId): array
    {
        $this->calls[] = ['method' => 'listServices', 'args' => [$clientId]];
        return [[
            'id' => 9,
            'name' => 'Starter Hosting',
            'domain' => 'example.com',
            'status' => 'Active',
            'billingcycle' => 'Monthly',
            'nextduedate' => '2026-11-01',
        ]];
    }

    public function listInvoices(int $clientId, string $status): array
    {
        $this->calls[] = ['method' => 'listInvoices', 'args' => [$clientId, $status]];
        return $this->invoices[$clientId] ?? [];
    }

    public function listDomains(int $clientId): array
    {
        $this->calls[] = ['method' => 'listDomains', 'args' => [$clientId]];
        return [];
    }

    public function listTickets(int $clientId, int $limit): array
    {
        $this->calls[] = ['method' => 'listTickets', 'args' => [$clientId, $limit]];
        return [];
    }

    public function getTicket(int $ticketId): ?array
    {
        $this->calls[] = ['method' => 'getTicket', 'args' => [$ticketId]];
        return $this->tickets[$ticketId] ?? null;
    }

    public function openTicket(array $params): array
    {
        $this->calls[] = ['method' => 'openTicket', 'args' => [$params]];
        if (($params['deptid'] ?? 0) <= 0) {
            throw new RuntimeException('department missing');
        }
        return ['id' => 44, 'tid' => 'ABC-100'];
    }

    public function addTicketReply(int $ticketId, string $message, ?int $clientId): void
    {
        $this->calls[] = ['method' => 'addTicketReply', 'args' => [$ticketId, $message, $clientId]];
    }
}
