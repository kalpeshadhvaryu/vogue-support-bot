<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tests;

use PHPUnit\Framework\TestCase;
use VogueHosting\SupportBot\Http\HttpClient;
use VogueHosting\SupportBot\Http\HttpResponse;
use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;
use VogueHosting\SupportBot\Knowledge\KnowledgeSelector;
use VogueHosting\SupportBot\Llm\ChatOrchestrator;
use VogueHosting\SupportBot\Llm\GrokClient;
use VogueHosting\SupportBot\Settings;
use VogueHosting\SupportBot\Tests\Support\FakeGateway;
use VogueHosting\SupportBot\Tools\ToolArgumentValidator;
use VogueHosting\SupportBot\Tools\WhmcsToolExecutor;

final class GrokClientTest extends TestCase
{
    public function testParsesToolCallsTextAndErrors(): void
    {
        $tool = GrokClient::parse(200, json_encode([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'list_services', 'arguments' => '{}'],
                    ]],
                ],
            ]],
            'usage' => ['total_tokens' => 12],
        ], JSON_THROW_ON_ERROR));
        self::assertFalse($tool->failed());
        self::assertNull($tool->content);
        self::assertSame('list_services', $tool->toolCalls[0]->name);
        self::assertSame(12, $tool->totalTokens);

        $text = GrokClient::parse(200, json_encode([
            'choices' => [[
                'message' => ['content' => [['type' => 'text', 'text' => 'Hello']]],
            ]],
        ], JSON_THROW_ON_ERROR));
        self::assertSame('Hello', $text->content);

        $error = GrokClient::parse(401, '{"error":{"message":"bad key"}}');
        self::assertTrue($error->failed());
        self::assertSame('bad key', $error->error);
    }

    public function testOrchestratorRunsAToolThenReturnsText(): void
    {
        $http = new SequenceHttp([
            new HttpResponse(200, json_encode([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'list_services', 'arguments' => '{}'],
                        ]],
                    ],
                ]],
                'usage' => ['total_tokens' => 10],
            ], JSON_THROW_ON_ERROR), null),
            new HttpResponse(200, json_encode([
                'choices' => [[
                    'message' => ['content' => 'You have Starter Hosting on example.com.'],
                ]],
                'usage' => ['total_tokens' => 6],
            ], JSON_THROW_ON_ERROR), null),
        ]);
        $gateway = new FakeGateway();
        $executor = new WhmcsToolExecutor(
            $gateway,
            new KnowledgeSelector(),
            [],
            1,
            [],
            null,
            null,
            true,
        );
        $result = (new ChatOrchestrator(new GrokClient($http), new ToolArgumentValidator(), new KnowledgeSelector()))
            ->respond(
                Settings::fromArray(['xai_api_key' => 'test-key', 'xai_model' => 'grok-4.7']),
                new Identity(IdentityLevel::Verified, 'clientarea', 4),
                [],
                'What hosting do I have?',
                [],
                0,
                null,
                $executor,
                'abc123',
            );
        self::assertFalse($result->failed);
        self::assertSame('You have Starter Hosting on example.com.', $result->text);
        self::assertSame(16, $result->tokens);
        self::assertSame('listServices', $gateway->calls[0]['method']);
        self::assertSame([4], $gateway->calls[0]['args']);
        $second = json_decode($http->bodies[1], true);
        self::assertSame('tool', $second['messages'][array_key_last($second['messages'])]['role']);
        self::assertStringContainsString('x-grok-conv-id: abc123', implode("\n", $http->headers[0]));
    }

    public function testVerificationUnlocksInvoicesInTheSameTurn(): void
    {
        $http = new SequenceHttp([
            new HttpResponse(200, json_encode([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_verify',
                            'type' => 'function',
                            'function' => [
                                'name' => 'verify_identity',
                                'arguments' => '{"method":"email","value":"asha@example.com"}',
                            ],
                        ]],
                    ],
                ]],
                'usage' => ['total_tokens' => 4],
            ], JSON_THROW_ON_ERROR), null),
            new HttpResponse(200, json_encode([
                'choices' => [[
                    'message' => [
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_invoices',
                            'type' => 'function',
                            'function' => ['name' => 'list_invoices', 'arguments' => '{}'],
                        ]],
                    ],
                ]],
                'usage' => ['total_tokens' => 4],
            ], JSON_THROW_ON_ERROR), null),
            new HttpResponse(200, json_encode([
                'choices' => [[
                    'message' => ['content' => 'Your latest invoice is unpaid.'],
                ]],
                'usage' => ['total_tokens' => 4],
            ], JSON_THROW_ON_ERROR), null),
        ]);
        $gateway = new FakeGateway();
        $gateway->clients[7] = [
            'id' => 7,
            'email' => 'asha@example.com',
            'phonenumber' => '9876543210',
            'firstname' => 'Asha',
            'lastname' => 'Rao',
        ];
        $gateway->invoices[7] = [[
            'id' => 104321,
            'invoicenum' => '104321',
            'date' => '2026-09-01',
            'duedate' => '2026-09-08',
            'total' => '10.00',
            'status' => 'Unpaid',
        ]];
        $executor = new WhmcsToolExecutor($gateway, new KnowledgeSelector(), [], 1, [], null, null, true);
        $result = (new ChatOrchestrator(new GrokClient($http), new ToolArgumentValidator(), new KnowledgeSelector()))
            ->respond(
                Settings::fromArray(['xai_api_key' => 'test-key']),
                new Identity(IdentityLevel::Linked, 'whatsapp', 7, '919876543210', [7]),
                [],
                'What do I owe? My email is asha@example.com',
                [],
                0,
                null,
                $executor,
            );
        self::assertSame('Your latest invoice is unpaid.', $result->text);
        self::assertSame(7, $result->verifiedClientId);
        $methods = array_column($gateway->calls, 'method');
        self::assertContains('listInvoices', $methods);
        $second = json_decode($http->bodies[1], true);
        $toolNames = array_map(static fn (array $tool): string => $tool['function']['name'], $second['tools']);
        self::assertContains('list_invoices', $toolNames);
    }
}

final class SequenceHttp implements HttpClient
{
    /** @var list<string> */
    public array $bodies = [];

    /** @var list<list<string>> */
    public array $headers = [];

    /**
     * @param list<HttpResponse> $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function post(string $url, array $headers, string $body, int $timeoutSeconds): HttpResponse
    {
        $this->bodies[] = $body;
        $this->headers[] = $headers;
        $next = array_shift($this->responses);
        return $next ?? new HttpResponse(500, '', 'no response');
    }
}
