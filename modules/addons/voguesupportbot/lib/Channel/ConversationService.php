<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Channel;

use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Knowledge\KnowledgeCache;
use VogueHosting\SupportBot\Knowledge\KnowledgeSelector;
use VogueHosting\SupportBot\Llm\ChatOrchestrator;
use VogueHosting\SupportBot\Logging\WhmcsLogger;
use VogueHosting\SupportBot\Settings;
use VogueHosting\SupportBot\Storage\Conversation;
use VogueHosting\SupportBot\Storage\ConversationStore;
use VogueHosting\SupportBot\Text;
use VogueHosting\SupportBot\Tools\WhmcsToolExecutor;

final class ConversationService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ConversationStore $store,
        private readonly KnowledgeCache $knowledge,
        private readonly ChatOrchestrator $orchestrator,
        private readonly KnowledgeSelector $selector,
        private readonly WhmcsLogger $logger,
        private readonly \VogueHosting\SupportBot\Whmcs\WhmcsGateway $gateway,
    ) {
    }

    public function reply(Conversation $conversation, Identity $identity, string $userMessage): AssistantReply
    {
        $userMessage = str_replace("\0", '', trim($userMessage));
        $userMessage = Text::limit($userMessage, 2000);
        $history = $this->history($conversation->id);
        $this->store->addMessage($conversation->id, 'user', $userMessage);
        try {
            $documents = $this->knowledge->documents($this->settings->knowledgeTtlSeconds());
        } catch (\Throwable $exception) {
            $this->logger->activity('Knowledge cache failed: ' . $exception->getMessage());
            $documents = [];
        }
        $transcript = $history;
        $transcript[] = ['role' => 'user', 'content' => $userMessage];
        $executor = new WhmcsToolExecutor(
            $this->gateway,
            $this->selector,
            $documents,
            $this->settings->handoffDepartmentId(),
            $transcript,
            $conversation->handoffTicketId,
            $conversation->handoffTicketTid,
            $this->settings->apiAdminUsername() !== '',
        );
        try {
            $result = $this->orchestrator->respond(
                $this->settings,
                $identity,
                $history,
                $userMessage,
                $documents,
                $conversation->tokenUsage,
                $conversation->handoffTicketTid,
                $executor,
                $conversation->publicId,
            );
        } catch (\Throwable $exception) {
            $this->logger->activity('Chat failed: ' . $exception->getMessage());
            $fallback = 'I am having trouble reaching the assistant right now. Please try again shortly, or open a ticket from the client area.';
            $this->store->addMessage($conversation->id, 'assistant', $fallback);
            return new AssistantReply($fallback, false, null);
        }
        foreach ($result->toolTrace as $trace) {
            $this->store->addMessage($conversation->id, 'tool', $trace['content'], $trace['name']);
        }
        $this->store->addMessage($conversation->id, 'assistant', $result->text);
        $this->store->addTokens($conversation->id, $result->tokens);
        if ($result->verifiedClientId !== null) {
            $this->store->setIdentity($conversation->id, $result->verifiedClientId, 'verified');
            $this->logger->activity('WhatsApp billing check passed for client ' . $result->verifiedClientId);
        }
        if ($result->handoffTicketId !== null && $result->handoffTicketTid !== null) {
            $this->store->setHandoff($conversation->id, $result->handoffTicketId, $result->handoffTicketTid);
            $this->logger->activity('Handoff ticket ' . $result->handoffTicketTid . ' on ' . $identity->channel);
        }
        if ($result->failed) {
            $this->logger->moduleCall('grok', ['model' => $this->settings->model()], ['error' => 'completion failed'], [$this->settings->xaiApiKey()]);
        }
        return new AssistantReply($result->text, $result->handoffTicketTid !== null, $result->handoffTicketTid);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function history(int $conversationId): array
    {
        $history = [];
        foreach ($this->store->messages($conversationId, false) as $message) {
            $history[] = ['role' => $message['role'], 'content' => $message['content']];
        }
        return array_slice($history, -10);
    }
}
