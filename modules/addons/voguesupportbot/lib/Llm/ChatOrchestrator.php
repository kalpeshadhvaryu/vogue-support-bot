<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Llm;

use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;
use VogueHosting\SupportBot\Knowledge\KnowledgeDocument;
use VogueHosting\SupportBot\Knowledge\KnowledgeSelector;
use VogueHosting\SupportBot\Settings;
use VogueHosting\SupportBot\Text;
use VogueHosting\SupportBot\Tools\ToolArgumentValidator;
use VogueHosting\SupportBot\Tools\ToolCatalog;
use VogueHosting\SupportBot\Tools\WhmcsToolExecutor;

final class ChatOrchestrator
{
    private const MAX_TOOL_ROUNDS = 3;

    public function __construct(
        private readonly GrokClient $grok,
        private readonly ToolArgumentValidator $validator,
        private readonly KnowledgeSelector $selector,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $history
     * @param list<KnowledgeDocument> $documents
     * @param list<array{role: string, content: string}> $transcript
     */
    public function respond(
        Settings $settings,
        Identity $identity,
        array $history,
        string $userMessage,
        array $documents,
        int $tokensUsed,
        ?string $existingHandoffTid,
        WhmcsToolExecutor $executor,
        string $conversationPublicId = '',
    ): OrchestratorResult {
        if ($settings->xaiApiKey() === '') {
            return $this->failure($tokensUsed, 'The assistant is not configured yet. Please open a support ticket and our team will help.');
        }
        if ($tokensUsed >= $settings->tokenBudget()) {
            return $this->failure($tokensUsed, 'This chat has reached its limit. Please open a ticket, or send "new chat" on WhatsApp to start again.');
        }
        $selected = [];
        foreach ($this->selector->select($userMessage, $documents) as $scored) {
            $selected[] = $scored->document;
        }
        $messages = [[
            'role' => 'system',
            'content' => SystemPrompt::build(
                $settings->brandPrompt(),
                $settings->extraContext(),
                $selected,
                $identity,
                $existingHandoffTid,
            ),
        ]];
        foreach ($history as $message) {
            if (!in_array($message['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $messages[] = [
                'role' => $message['role'],
                'content' => Text::limit($message['content'], 2000),
            ];
        }
        $messages[] = ['role' => 'user', 'content' => Text::limit($userMessage, 2000)];
        $tools = ToolCatalog::openAiTools($identity);
        $trace = [];
        $verifiedClientId = null;
        $handoffId = null;
        $handoffTid = null;
        $tokens = 0;

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $sendTools = $round < self::MAX_TOOL_ROUNDS ? $tools : [];
            $completion = $this->grok->complete(
                $settings->xaiApiKey(),
                $settings->model(),
                $messages,
                $sendTools,
                $settings->maxTokens(),
                $conversationPublicId,
            );
            $tokens += max(0, $completion->totalTokens);
            if ($completion->failed()) {
                return new OrchestratorResult(
                    'I am having trouble reaching the assistant right now. Please try again shortly, or open a ticket from the client area.',
                    $tokens,
                    $verifiedClientId,
                    $handoffId,
                    $handoffTid,
                    $trace,
                    true,
                );
            }
            if ($completion->toolCalls === [] || $sendTools === []) {
                $text = trim((string) $completion->content);
                if ($text === '') {
                    $text = 'I was not able to finish that. Please try again, or ask me to connect you with a person.';
                }
                if ($handoffTid !== null && !str_contains($text, $handoffTid)) {
                    $text .= "\n\nI've passed this to our team. Your ticket number is {$handoffTid}. A person will follow up.";
                }
                return new OrchestratorResult($text, $tokens, $verifiedClientId, $handoffId, $handoffTid, $trace, false);
            }
            $encodedCalls = [];
            foreach ($completion->toolCalls as $call) {
                $encodedCalls[] = [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        'arguments' => $call->argumentsJson,
                    ],
                ];
            }
            $messages[] = [
                'role' => 'assistant',
                'content' => $completion->content,
                'tool_calls' => $encodedCalls,
            ];
            foreach ($completion->toolCalls as $call) {
                $execution = $this->runTool($call->name, $call->argumentsJson, $identity, $executor);
                if ($execution->verifiedClientId !== null) {
                    $verifiedClientId = $execution->verifiedClientId;
                    $identity = new Identity(
                        IdentityLevel::Verified,
                        $identity->channel,
                        $verifiedClientId,
                        $identity->phone,
                        [$verifiedClientId],
                    );
                    $tools = ToolCatalog::openAiTools($identity);
                }
                if ($execution->handoffTicketId !== null) {
                    $handoffId = $execution->handoffTicketId;
                    $handoffTid = $execution->handoffTicketTid;
                }
                $encoded = json_encode($execution->forModel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $content = $encoded === false ? '{"ok":false,"error":"encode_failed"}' : $encoded;
                $trace[] = ['name' => $call->name, 'content' => Text::limit($content, 4000)];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'content' => $content,
                ];
            }
        }
        return $this->failure($tokens, 'I was not able to finish that. Please try again, or ask me to connect you with a person.');
    }

    private function runTool(string $name, string $argumentsJson, Identity $identity, WhmcsToolExecutor $executor): \VogueHosting\SupportBot\Tools\ToolExecution
    {
        $decoded = json_decode($argumentsJson === '' ? '{}' : $argumentsJson, true);
        if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            return new \VogueHosting\SupportBot\Tools\ToolExecution([
                'ok' => false,
                'error' => 'invalid_arguments',
            ]);
        }
        $validation = $this->validator->validate($name, $decoded, $identity);
        if (!$validation->ok) {
            return new \VogueHosting\SupportBot\Tools\ToolExecution([
                'ok' => false,
                'error' => $validation->error,
            ]);
        }
        try {
            return $executor->execute($name, $validation->arguments, $identity);
        } catch (\Throwable $exception) {
            if (function_exists('logActivity')) {
                logActivity('Vogue Support Bot: tool failed: ' . substr($exception->getMessage(), 0, 300));
            }
            return new \VogueHosting\SupportBot\Tools\ToolExecution([
                'ok' => false,
                'error' => 'tool_failed',
            ]);
        }
    }

    private function failure(int $tokens, string $text): OrchestratorResult
    {
        return new OrchestratorResult($text, $tokens, null, null, null, [], false);
    }
}
