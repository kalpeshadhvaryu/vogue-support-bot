<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Llm;

use VogueHosting\SupportBot\Http\HttpClient;

/**
 * xAI chat completions at https://api.x.ai/v1/chat/completions.
 * Tool definitions use the OpenAI-compatible function shape.
 *
 * xAI also documents a newer Responses API at /v1/responses. v1 of this addon
 * stays on chat completions, which the Grok 4.7 docs still list as supported.
 */
final class GrokClient
{
    public const ENDPOINT = 'https://api.x.ai/v1/chat/completions';

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     */
    public function complete(
        string $apiKey,
        string $model,
        array $messages,
        array $tools,
        int $maxTokens,
        string $conversationId,
    ): GrokCompletion {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return new GrokCompletion(null, [], 0, 'Could not encode the model request.');
        }
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'User-Agent: VogueSupportBot/1.0',
        ];
        if ($conversationId !== '') {
            $headers[] = 'x-grok-conv-id: ' . $conversationId;
        }
        $response = $this->http->post(self::ENDPOINT, $headers, $body, 45);
        if ($response->error !== null) {
            return new GrokCompletion(null, [], 0, $response->error);
        }
        return self::parse($response->status, $response->body);
    }

    public static function parse(int $status, string $body): GrokCompletion
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new GrokCompletion(null, [], 0, $status >= 400 ? 'The model request failed.' : 'The model returned an unreadable response.');
        }
        if ($status >= 400 || isset($decoded['error'])) {
            $message = 'The model request failed.';
            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message = $decoded['error']['message'];
            }
            return new GrokCompletion(null, [], 0, $message);
        }
        $message = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            return new GrokCompletion(null, [], 0, 'The model returned no message.');
        }
        $content = self::contentText($message['content'] ?? null);
        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            if (!is_array($call)) {
                continue;
            }
            $id = isset($call['id']) && is_string($call['id']) ? $call['id'] : '';
            $name = $call['function']['name'] ?? '';
            $arguments = $call['function']['arguments'] ?? '';
            if ($id === '' || !is_string($name) || $name === '' || !is_string($arguments)) {
                continue;
            }
            $toolCalls[] = new ToolCall($id, $name, $arguments);
        }
        $tokens = 0;
        if (isset($decoded['usage']['total_tokens']) && is_numeric($decoded['usage']['total_tokens'])) {
            $tokens = (int) $decoded['usage']['total_tokens'];
        }
        return new GrokCompletion($content, $toolCalls, $tokens, null);
    }

    private static function contentText(mixed $content): ?string
    {
        if ($content === null) {
            return null;
        }
        if (is_string($content)) {
            return $content;
        }
        if (!is_array($content)) {
            return null;
        }
        $text = '';
        foreach ($content as $part) {
            if (is_string($part)) {
                $text .= $part;
            } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }
        return $text;
    }
}
