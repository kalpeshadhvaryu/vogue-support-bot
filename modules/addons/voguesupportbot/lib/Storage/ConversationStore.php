<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Storage;

use VogueHosting\SupportBot\Text;

final class ConversationStore
{
    public function create(
        string $channel,
        ?int $clientId,
        string $externalKey,
        string $identityLevel,
        bool $withAccessToken,
    ): array {
        $publicId = bin2hex(random_bytes(16));
        $token = $withAccessToken ? bin2hex(random_bytes(32)) : '';
        $now = $this->now();
        $id = (int) $this->table(Schema::CONVERSATIONS)->insertGetId([
            'public_id' => $publicId,
            'access_token_hash' => $token === '' ? '' : hash('sha256', $token),
            'channel' => $channel,
            'client_id' => $clientId,
            'external_key' => $externalKey,
            'identity_level' => $identityLevel,
            'handoff_status' => 'none',
            'handoff_ticket_id' => null,
            'handoff_ticket_tid' => null,
            'last_customer_at' => null,
            'token_usage' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $conversation = $this->findById($id);
        if ($conversation === null) {
            throw new \RuntimeException('Could not create the conversation.');
        }
        return ['conversation' => $conversation, 'token' => $token];
    }

    public function findByPublicId(string $publicId): ?Conversation
    {
        if (preg_match('/^[a-f0-9]{32}$/', $publicId) !== 1) {
            return null;
        }
        $row = $this->table(Schema::CONVERSATIONS)->where('public_id', $publicId)->first();
        return $row === null ? null : $this->map($row);
    }

    public function findById(int $id): ?Conversation
    {
        $row = $this->table(Schema::CONVERSATIONS)->where('id', $id)->first();
        return $row === null ? null : $this->map($row);
    }

    public function findLatestByExternal(string $channel, string $externalKey): ?Conversation
    {
        if ($externalKey === '') {
            return null;
        }
        $row = $this->table(Schema::CONVERSATIONS)
            ->where('channel', $channel)
            ->where('external_key', $externalKey)
            ->orderBy('id', 'desc')
            ->first();
        return $row === null ? null : $this->map($row);
    }

    public function addMessage(int $conversationId, string $role, string $content, string $toolName = ''): void
    {
        $this->table(Schema::MESSAGES)->insert([
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => Text::limit($content, 20000),
            'tool_name' => $toolName,
            'created_at' => $this->now(),
        ]);
        $this->table(Schema::CONVERSATIONS)->where('id', $conversationId)->update([
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * @return list<array{role: string, content: string, tool_name: string}>
     */
    public function messages(int $conversationId, bool $includeTools): array
    {
        $query = $this->table(Schema::MESSAGES)->where('conversation_id', $conversationId)->orderBy('id');
        if (!$includeTools) {
            $query->whereIn('role', ['user', 'assistant']);
        }
        $messages = [];
        foreach ($query->get() as $row) {
            $messages[] = [
                'role' => (string) $row->role,
                'content' => (string) $row->content,
                'tool_name' => (string) $row->tool_name,
            ];
        }
        return $messages;
    }

    public function addTokens(int $conversationId, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }
        $this->table(Schema::CONVERSATIONS)->where('id', $conversationId)->increment('token_usage', $tokens);
    }

    public function markCustomerMessage(int $conversationId, string $utcDateTime): void
    {
        $this->table(Schema::CONVERSATIONS)->where('id', $conversationId)->update([
            'last_customer_at' => $utcDateTime,
            'updated_at' => $this->now(),
        ]);
    }

    public function setIdentity(int $conversationId, ?int $clientId, string $level): void
    {
        $this->table(Schema::CONVERSATIONS)->where('id', $conversationId)->update([
            'client_id' => $clientId,
            'identity_level' => $level,
            'updated_at' => $this->now(),
        ]);
    }

    public function setHandoff(int $conversationId, int $ticketId, string $tid): void
    {
        $this->table(Schema::CONVERSATIONS)->where('id', $conversationId)->update([
            'handoff_status' => 'open',
            'handoff_ticket_id' => $ticketId,
            'handoff_ticket_tid' => $tid,
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * @return list<Conversation>
     */
    public function latest(int $limit): array
    {
        $rows = $this->table(Schema::CONVERSATIONS)->orderBy('id', 'desc')->limit($limit)->get();
        $conversations = [];
        foreach ($rows as $row) {
            $conversations[] = $this->map($row);
        }
        return $conversations;
    }

    private function map(object $row): Conversation
    {
        return new Conversation(
            (int) $row->id,
            (string) $row->public_id,
            (string) $row->access_token_hash,
            (string) $row->channel,
            $row->client_id === null ? null : (int) $row->client_id,
            (string) $row->external_key,
            (string) $row->identity_level,
            (string) $row->handoff_status,
            $row->handoff_ticket_id === null ? null : (int) $row->handoff_ticket_id,
            $row->handoff_ticket_tid === null ? null : (string) $row->handoff_ticket_tid,
            $row->last_customer_at === null ? null : (string) $row->last_customer_at,
            (int) $row->token_usage,
        );
    }

    private function table(string $name): \Illuminate\Database\Query\Builder
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            throw new \RuntimeException('WHMCS database is not available.');
        }
        return \WHMCS\Database\Capsule::table($name);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
