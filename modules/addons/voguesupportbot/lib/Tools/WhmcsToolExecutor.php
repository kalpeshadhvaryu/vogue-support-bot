<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tools;

use VogueHosting\SupportBot\Identity\BillingVerifier;
use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;
use VogueHosting\SupportBot\Knowledge\KnowledgeDocument;
use VogueHosting\SupportBot\Knowledge\KnowledgeSelector;
use VogueHosting\SupportBot\Text;
use VogueHosting\SupportBot\Whmcs\WhmcsGateway;

final class WhmcsToolExecutor
{
    /**
     * @param list<KnowledgeDocument> $documents
     * @param list<array{role: string, content: string}> $transcript
     */
    public function __construct(
        private readonly WhmcsGateway $gateway,
        private readonly KnowledgeSelector $selector,
        private readonly array $documents,
        private readonly int $departmentId,
        private readonly array $transcript,
        private readonly ?int $existingHandoffTicketId,
        private readonly ?string $existingHandoffTid,
        private readonly bool $adminTicketsEnabled,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(string $name, array $arguments, Identity $identity): ToolExecution
    {
        return match ($name) {
            'search_knowledge' => $this->search($arguments),
            'list_services' => $this->services($arguments, $identity),
            'list_domains' => $this->domains($identity),
            'list_tickets' => $this->tickets($arguments, $identity),
            'list_invoices' => $this->invoices($arguments, $identity),
            'open_ticket' => $this->openTicket($arguments, $identity),
            'reply_ticket' => $this->replyTicket($arguments, $identity),
            'handoff_to_human' => $this->handoff($arguments, $identity),
            'verify_identity' => $this->verify($arguments, $identity),
            default => new ToolExecution(['ok' => false, 'error' => 'unknown_tool']),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function search(array $arguments): ToolExecution
    {
        $query = is_string($arguments['query'] ?? null) ? $arguments['query'] : '';
        $hits = [];
        foreach ($this->selector->select($query, $this->documents) as $scored) {
            $hits[] = [
                'source' => $scored->document->source,
                'title' => $scored->document->title,
                'snippet' => Text::limit($scored->document->body, 800),
                'url' => $scored->document->url,
            ];
        }
        return new ToolExecution(['ok' => true, 'results' => $hits]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function services(array $arguments, Identity $identity): ToolExecution
    {
        if (!$identity->canUseAccountTools() || $identity->clientId === null) {
            return $this->denied();
        }
        $rows = $this->gateway->listServices($identity->clientId);
        $status = $arguments['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => ($row['status'] ?? '') === $status));
        }
        return new ToolExecution(['ok' => true, 'services' => array_slice($rows, 0, 25)]);
    }

    private function domains(Identity $identity): ToolExecution
    {
        if (!$identity->canUseAccountTools() || $identity->clientId === null) {
            return $this->denied();
        }
        return new ToolExecution([
            'ok' => true,
            'domains' => array_slice($this->gateway->listDomains($identity->clientId), 0, 25),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tickets(array $arguments, Identity $identity): ToolExecution
    {
        if (!$identity->canUseAccountTools() || $identity->clientId === null) {
            return $this->denied();
        }
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 5;
        $limit = max(1, min(15, $limit));
        return new ToolExecution([
            'ok' => true,
            'tickets' => $this->gateway->listTickets($identity->clientId, $limit),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function invoices(array $arguments, Identity $identity): ToolExecution
    {
        if (!$identity->canViewBilling() || $identity->clientId === null) {
            return new ToolExecution([
                'ok' => false,
                'error' => 'verification_required',
                'message' => 'Confirm the account email or the last 4 digits of an invoice before sharing billing details.',
            ]);
        }
        $status = is_string($arguments['status'] ?? null) ? $arguments['status'] : 'Unpaid';
        return new ToolExecution([
            'ok' => true,
            'invoices' => array_slice($this->gateway->listInvoices($identity->clientId, $status), 0, 15),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function openTicket(array $arguments, Identity $identity): ToolExecution
    {
        if ($this->departmentId <= 0) {
            return new ToolExecution(['ok' => false, 'error' => 'department_not_configured']);
        }
        $subject = Text::singleLine((string) $arguments['subject'], 180);
        $message = Text::limit((string) $arguments['message'], 4000);
        $message .= $this->footer($identity);
        $params = [
            'deptid' => $this->departmentId,
            'subject' => $subject,
            'message' => $message,
        ];
        if ($identity->canUseAccountTools() && $identity->clientId !== null) {
            $params['clientid'] = $identity->clientId;
        } else {
            $params['name'] = Text::singleLine((string) ($arguments['name'] ?? ''), 100);
            $params['email'] = strtolower(trim((string) ($arguments['email'] ?? '')));
        }
        $opened = $this->gateway->openTicket($params);
        return new ToolExecution([
            'ok' => true,
            'ticket_id' => $opened['id'],
            'tid' => $opened['tid'],
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function replyTicket(array $arguments, Identity $identity): ToolExecution
    {
        if (!$identity->canUseAccountTools() || $identity->clientId === null) {
            return $this->denied();
        }
        $ticketId = (int) $arguments['ticket_id'];
        $ticket = $this->gateway->getTicket($ticketId);
        if ($ticket === null || $ticket['userid'] !== $identity->clientId) {
            return new ToolExecution(['ok' => false, 'error' => 'ticket_not_found']);
        }
        $this->gateway->addTicketReply($ticketId, Text::limit((string) $arguments['message'], 4000), $identity->clientId);
        return new ToolExecution(['ok' => true, 'ticket_id' => $ticketId, 'tid' => $ticket['tid']]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function handoff(array $arguments, Identity $identity): ToolExecution
    {
        if ($this->departmentId <= 0) {
            return new ToolExecution(['ok' => false, 'error' => 'department_not_configured']);
        }
        $reason = Text::limit((string) $arguments['reason'], 500);
        $body = "Handoff requested.\nReason: {$reason}\n\n--- Transcript ---\n" . $this->renderTranscript();
        $body = Text::limit($body, 12000);
        if ($this->existingHandoffTicketId !== null && $this->existingHandoffTicketId > 0) {
            $this->gateway->addTicketReply($this->existingHandoffTicketId, $body, $identity->clientId);
            $tid = $this->existingHandoffTid ?? (string) $this->existingHandoffTicketId;
            return new ToolExecution(
                ['ok' => true, 'ticket_id' => $this->existingHandoffTicketId, 'tid' => $tid, 'updated' => true],
                null,
                $this->existingHandoffTicketId,
                $tid,
            );
        }
        $params = [
            'deptid' => $this->departmentId,
            'subject' => 'Support bot handoff',
            'message' => $body,
        ];
        if ($identity->canUseAccountTools() && $identity->clientId !== null) {
            $params['clientid'] = $identity->clientId;
        } elseif ($this->adminTicketsEnabled) {
            // Anonymous handoff is opened by the configured API admin so WHMCS
            // does not need a visitor email address. The transcript stays on the ticket.
            $params['admin'] = true;
            if ($identity->phone !== '') {
                $params['message'] .= "\n\nWhatsApp: +" . $identity->phone;
            }
        } else {
            return new ToolExecution([
                'ok' => false,
                'error' => 'contact_required',
                'message' => 'Ask for the customer\'s name and email, then call open_ticket. Anonymous handoff needs an API admin username or a client on the ticket.',
            ]);
        }
        $opened = $this->gateway->openTicket($params);
        return new ToolExecution(
            ['ok' => true, 'ticket_id' => $opened['id'], 'tid' => $opened['tid'], 'updated' => false],
            null,
            $opened['id'],
            $opened['tid'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function verify(array $arguments, Identity $identity): ToolExecution
    {
        if ($identity->channel !== 'whatsapp' || $identity->level === IdentityLevel::Verified) {
            return new ToolExecution(['ok' => false, 'error' => 'tool_not_permitted']);
        }
        $candidateIds = $identity->phoneCandidateIds;
        if ($identity->level === IdentityLevel::Linked && $identity->clientId !== null) {
            $candidateIds = [$identity->clientId];
        }
        if (count($candidateIds) < 1 || count($candidateIds) > 5) {
            return new ToolExecution([
                'ok' => false,
                'error' => 'not_matched',
                'message' => 'No single account is linked to this phone number. Ask the customer to use the client area.',
            ]);
        }
        $method = (string) $arguments['method'];
        $value = (string) $arguments['value'];
        $candidates = [];
        foreach ($candidateIds as $candidateId) {
            $client = $this->gateway->getClient($candidateId);
            if ($client === null) {
                continue;
            }
            $labels = [];
            if ($method === 'invoice_last4') {
                foreach ($this->gateway->listInvoices($candidateId, 'All') as $invoice) {
                    $labels[] = (string) ($invoice['id'] ?? '');
                    $labels[] = (string) ($invoice['invoicenum'] ?? '');
                }
            }
            $candidates[] = [
                'id' => $candidateId,
                'email' => $client['email'],
                'invoice_labels' => $labels,
            ];
        }
        $matched = BillingVerifier::match($method, $value, $candidates);
        if ($matched === null) {
            return new ToolExecution([
                'ok' => false,
                'error' => 'not_matched',
                'message' => 'That does not match the account on this phone number.',
            ]);
        }
        return new ToolExecution(['ok' => true, 'verified' => true], $matched);
    }

    private function denied(): ToolExecution
    {
        return new ToolExecution([
            'ok' => false,
            'error' => 'verification_required',
            'message' => 'This customer is not verified. Offer to log in, or on WhatsApp confirm their email or invoice last 4 digits.',
        ]);
    }

    private function footer(Identity $identity): string
    {
        $line = "\n\n— Sent via Vogue Support Bot (" . $identity->channel . ").";
        if ($identity->phone !== '') {
            $line .= "\nWhatsApp: +" . $identity->phone;
        }
        return $line;
    }

    private function renderTranscript(): string
    {
        $lines = [];
        foreach ($this->transcript as $message) {
            $role = $message['role'] === 'assistant' ? 'assistant' : 'user';
            if (!in_array($message['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $lines[] = '[' . $role . '] ' . Text::limit($message['content'], 1000);
        }
        return implode("\n", $lines);
    }
}
