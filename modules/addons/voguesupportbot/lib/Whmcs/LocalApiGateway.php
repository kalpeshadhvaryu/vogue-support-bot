<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Whmcs;

use RuntimeException;
use VogueHosting\SupportBot\Knowledge\KnowledgeDocument;
use VogueHosting\SupportBot\Knowledge\ProductSummary;
use VogueHosting\SupportBot\Logging\WhmcsLogger;
use VogueHosting\SupportBot\Phone\PhoneNormalizer;
use VogueHosting\SupportBot\Text;

/**
 * WHMCS localAPI plus a direct read of public knowledgebase rows.
 * There is no GetKnowledgebase localAPI command in WHMCS 8, so articles are
 * read from tblknowledgebase and private articles are skipped.
 */
final class LocalApiGateway implements WhmcsGateway
{
    public function __construct(
        private readonly string $adminUsername,
        private readonly PhoneNormalizer $phones,
        private readonly WhmcsLogger $logger,
    ) {
    }

    public function knowledgeDocuments(): array
    {
        $documents = [];
        foreach ($this->products() as $document) {
            $documents[] = $document;
        }
        foreach ($this->announcements() as $document) {
            $documents[] = $document;
        }
        foreach ($this->articles() as $document) {
            $documents[] = $document;
        }
        return $documents;
    }

    public function findClientsByPhone(string $phone): array
    {
        $canonical = $this->phones->canonicalize($phone);
        $national = substr($canonical, -10);
        if (strlen($national) < 8 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return [];
        }
        $rows = \WHMCS\Database\Capsule::table('tblclients')
            ->select(['id', 'firstname', 'lastname', 'email', 'phonenumber'])
            ->where('phonenumber', 'like', '%' . $national)
            ->limit(20)
            ->get();
        $clients = [];
        foreach ($rows as $row) {
            $clients[] = [
                'id' => (int) $row->id,
                'firstname' => (string) $row->firstname,
                'lastname' => (string) $row->lastname,
                'email' => (string) $row->email,
                'phonenumber' => (string) $row->phonenumber,
            ];
        }
        return $clients;
    }

    public function getClient(int $clientId): ?array
    {
        $result = $this->call('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
        if (($result['result'] ?? '') !== 'success') {
            return null;
        }
        if (isset($result['client']) && is_array($result['client'])) {
            $result = array_merge($result, $result['client']);
        }
        $email = $result['email'] ?? '';
        if (!is_string($email) || $email === '') {
            return null;
        }
        return [
            'id' => $clientId,
            'email' => $email,
            'phonenumber' => is_string($result['phonenumber'] ?? null) ? $result['phonenumber'] : '',
            'firstname' => is_string($result['firstname'] ?? null) ? $result['firstname'] : '',
            'lastname' => is_string($result['lastname'] ?? null) ? $result['lastname'] : '',
        ];
    }

    public function listServices(int $clientId): array
    {
        $result = $this->call('GetClientsProducts', [
            'clientid' => $clientId,
            'limitnum' => 50,
        ]);
        $services = [];
        foreach (WhmcsLists::asList($result['products']['product'] ?? []) as $row) {
            $services[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? $row['translated_name'] ?? ''),
                'domain' => (string) ($row['domain'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'billingcycle' => (string) ($row['billingcycle'] ?? ''),
                'nextduedate' => (string) ($row['nextduedate'] ?? ''),
            ];
        }
        return $services;
    }

    public function listInvoices(int $clientId, string $status): array
    {
        $params = ['userid' => $clientId, 'clientid' => $clientId, 'limitnum' => 25];
        if ($status !== '' && $status !== 'All') {
            $params['status'] = $status;
        }
        $result = $this->call('GetInvoices', $params);
        $invoices = [];
        foreach (WhmcsLists::asList($result['invoices']['invoice'] ?? []) as $row) {
            $invoices[] = [
                'id' => (int) ($row['id'] ?? 0),
                'invoicenum' => (string) ($row['invoicenum'] ?? ''),
                'date' => (string) ($row['date'] ?? ''),
                'duedate' => (string) ($row['duedate'] ?? ''),
                'total' => (string) ($row['total'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
            ];
        }
        return $invoices;
    }

    public function listDomains(int $clientId): array
    {
        $result = $this->call('GetClientsDomains', [
            'clientid' => $clientId,
            'limitnum' => 50,
        ]);
        $domains = [];
        foreach (WhmcsLists::asList($result['domains']['domain'] ?? []) as $row) {
            $domains[] = [
                'id' => (int) ($row['id'] ?? 0),
                'domain' => (string) ($row['domainname'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'expirydate' => (string) ($row['expirydate'] ?? ''),
                'registrar' => (string) ($row['registrar'] ?? ''),
            ];
        }
        return $domains;
    }

    public function listTickets(int $clientId, int $limit): array
    {
        $result = $this->call('GetTickets', [
            'clientid' => $clientId,
            'limitnum' => max(1, min(15, $limit)),
        ]);
        $tickets = [];
        foreach (WhmcsLists::asList($result['tickets']['ticket'] ?? []) as $row) {
            $tickets[] = [
                'id' => (int) ($row['id'] ?? 0),
                'tid' => (string) ($row['tid'] ?? ''),
                'subject' => (string) ($row['subject'] ?? $row['title'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'lastreply' => (string) ($row['lastreply'] ?? ''),
            ];
        }
        return $tickets;
    }

    public function getTicket(int $ticketId): ?array
    {
        $result = $this->call('GetTicket', ['ticketid' => $ticketId]);
        if (($result['result'] ?? '') !== 'success') {
            return null;
        }
        $userId = $result['userid'] ?? $result['clientid'] ?? 0;
        return [
            'id' => $ticketId,
            'tid' => (string) ($result['tid'] ?? ''),
            'userid' => (int) $userId,
            'status' => (string) ($result['status'] ?? ''),
            'subject' => (string) ($result['subject'] ?? $result['title'] ?? ''),
        ];
    }

    public function openTicket(array $params): array
    {
        $post = [
            'deptid' => $params['deptid'],
            'subject' => $params['subject'],
            'message' => $params['message'],
            'priority' => 'Medium',
            'markdown' => false,
        ];
        if (isset($params['clientid'])) {
            $post['clientid'] = $params['clientid'];
        } elseif (!empty($params['admin'])) {
            $post['admin'] = true;
        } else {
            $post['name'] = $params['name'] ?? '';
            $post['email'] = $params['email'] ?? '';
        }
        $result = $this->call('OpenTicket', $post);
        if (($result['result'] ?? '') !== 'success') {
            $message = is_string($result['message'] ?? null) ? $result['message'] : 'OpenTicket failed.';
            throw new RuntimeException($message);
        }
        return [
            'id' => (int) ($result['id'] ?? 0),
            'tid' => (string) ($result['tid'] ?? ''),
        ];
    }

    public function addTicketReply(int $ticketId, string $message, ?int $clientId): void
    {
        $post = [
            'ticketid' => $ticketId,
            'message' => $message,
            'markdown' => false,
        ];
        if ($clientId !== null && $clientId > 0) {
            $post['clientid'] = $clientId;
        }
        $result = $this->call('AddTicketReply', $post);
        if (($result['result'] ?? '') !== 'success') {
            $error = is_string($result['message'] ?? null) ? $result['message'] : 'AddTicketReply failed.';
            throw new RuntimeException($error);
        }
    }

    /**
     * @return list<KnowledgeDocument>
     */
    private function products(): array
    {
        $result = $this->call('GetProducts', []);
        $documents = [];
        foreach (WhmcsLists::asList($result['products']['product'] ?? []) as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $pricing = ProductSummary::pricing(is_array($row['pricing'] ?? null) ? $row['pricing'] : []);
            $body = Text::plain((string) ($row['description'] ?? ''), 1500);
            if ($pricing !== '') {
                $body = trim($body . "\nPricing: " . $pricing);
            }
            $documents[] = new KnowledgeDocument(
                'product',
                (string) ($row['pid'] ?? $row['id'] ?? $name),
                $name,
                $body,
                is_string($row['product_url'] ?? null) ? $row['product_url'] : '',
            );
        }
        return $documents;
    }

    /**
     * @return list<KnowledgeDocument>
     */
    private function announcements(): array
    {
        $result = $this->call('GetAnnouncements', ['limitnum' => 20]);
        $documents = [];
        foreach (WhmcsLists::asList($result['announcements']['announcement'] ?? []) as $row) {
            $title = (string) ($row['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $documents[] = new KnowledgeDocument(
                'announcement',
                (string) ($row['id'] ?? $title),
                $title,
                Text::plain((string) ($row['announcement'] ?? ''), 2000),
                '',
            );
        }
        return $documents;
    }

    /**
     * @return list<KnowledgeDocument>
     */
    private function articles(): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return [];
        }
        try {
            $rows = \WHMCS\Database\Capsule::table('tblknowledgebase')
                ->select(['id', 'title', 'article', 'private'])
                ->orderBy('id', 'desc')
                ->limit(200)
                ->get();
        } catch (\Throwable $exception) {
            $this->logger->activity('Knowledgebase read failed: ' . $exception->getMessage());
            return [];
        }
        $documents = [];
        foreach ($rows as $row) {
            $private = strtolower(trim((string) ($row->private ?? '')));
            if (in_array($private, ['on', '1', 'true', 'yes'], true)) {
                continue;
            }
            $title = (string) $row->title;
            if ($title === '') {
                continue;
            }
            $documents[] = new KnowledgeDocument(
                'kb',
                (string) $row->id,
                $title,
                Text::plain((string) $row->article, 4000),
                '',
            );
        }
        return $documents;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function call(string $command, array $data): array
    {
        if (!function_exists('localAPI')) {
            throw new RuntimeException('localAPI is not available.');
        }
        $result = $this->adminUsername !== ''
            ? localAPI($command, $data, $this->adminUsername)
            : localAPI($command, $data);
        $this->logger->moduleCall($command, $data, $result);
        if (!is_array($result)) {
            return ['result' => 'error', 'message' => 'localAPI returned an unexpected response.'];
        }
        return $result;
    }
}
