<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Whmcs;

use VogueHosting\SupportBot\Knowledge\KnowledgeDocument;

interface WhmcsGateway
{
    /**
     * @return list<KnowledgeDocument>
     */
    public function knowledgeDocuments(): array;

    /**
     * @return list<array{id: int, firstname: string, lastname: string, email: string, phonenumber: string}>
     */
    public function findClientsByPhone(string $phone): array;

    /**
     * @return array{id: int, email: string, phonenumber: string, firstname: string, lastname: string}|null
     */
    public function getClient(int $clientId): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listServices(int $clientId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listInvoices(int $clientId, string $status): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listDomains(int $clientId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function listTickets(int $clientId, int $limit): array;

    /**
     * @return array{id: int, tid: string, userid: int, status: string, subject: string}|null
     */
    public function getTicket(int $ticketId): ?array;

    /**
     * @param array{deptid: int, subject: string, message: string, clientid?: int, name?: string, email?: string, admin?: bool} $params
     * @return array{id: int, tid: string}
     */
    public function openTicket(array $params): array;

    public function addTicketReply(int $ticketId, string $message, ?int $clientId): void;
}
