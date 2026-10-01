<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tools;

use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;

/**
 * Tool definitions shared by the validator and the chat-completions payload.
 * The model never supplies a client id; identity comes from the channel.
 */
final class ToolCatalog
{
    /**
     * @return array<string, array{description: string, access: string, properties: array<string, array<string, mixed>>, required: list<string>}>
     */
    public static function all(): array
    {
        return [
            'search_knowledge' => [
                'description' => 'Search Vogue Hosting public products, prices, knowledgebase articles, and announcements. Use this before answering a product or policy question.',
                'access' => 'public',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Short search query, without instructions.',
                    ],
                ],
                'required' => ['query'],
            ],
            'list_services' => [
                'description' => 'List hosting services and status for the linked client. Do not pass a client id. This does not include prices or passwords.',
                'access' => 'account',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['Active', 'Pending', 'Suspended', 'Terminated', 'Cancelled', 'Fraud', 'Completed'],
                        'description' => 'Optional WHMCS service status.',
                    ],
                ],
                'required' => [],
            ],
            'list_domains' => [
                'description' => 'List domains and status for the linked client. Do not pass a client id.',
                'access' => 'account',
                'properties' => [],
                'required' => [],
            ],
            'list_tickets' => [
                'description' => 'List recent support tickets for the linked client. Show the customer the tid, and use id only when calling reply_ticket.',
                'access' => 'account',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => 15,
                        'description' => 'How many tickets to return, from 1 to 15. Defaults to 5.',
                    ],
                ],
                'required' => [],
            ],
            'list_invoices' => [
                'description' => 'List invoices for a billing-verified client. On WhatsApp this requires verify_identity first. Do not pass a client id.',
                'access' => 'billing',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['Unpaid', 'Paid', 'Cancelled', 'Refunded', 'Collections', 'Payment Pending', 'All'],
                        'description' => 'Invoice status. Defaults to Unpaid.',
                    ],
                ],
                'required' => [],
            ],
            'open_ticket' => [
                'description' => 'Open a new support ticket. For a logged-in or phone-linked client, send subject and message only. For an anonymous visitor, also send their name and email. Never attach a ticket to an email address you looked up yourself.',
                'access' => 'public',
                'properties' => [
                    'subject' => ['type' => 'string', 'description' => 'Ticket subject.'],
                    'message' => ['type' => 'string', 'description' => 'Ticket message in the customer\'s words.'],
                    'name' => ['type' => 'string', 'description' => 'Required only for anonymous visitors.'],
                    'email' => ['type' => 'string', 'description' => 'Required only for anonymous visitors.'],
                ],
                'required' => ['subject', 'message'],
            ],
            'reply_ticket' => [
                'description' => 'Reply to a ticket owned by the linked client. ticket_id is the internal id from list_tickets, not the public tid.',
                'access' => 'account',
                'properties' => [
                    'ticket_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 2000000000,
                    'description' => 'Internal ticket id from list_tickets.',
                ],
                    'message' => ['type' => 'string', 'description' => 'Reply in the customer\'s words.'],
                ],
                'required' => ['ticket_id', 'message'],
            ],
            'handoff_to_human' => [
                'description' => 'Pass the conversation to a human. The server attaches the real transcript and returns a ticket number. Tell the customer a person will follow up and quote that ticket number.',
                'access' => 'public',
                'properties' => [
                    'reason' => ['type' => 'string', 'description' => 'Why a human should take over.'],
                ],
                'required' => ['reason'],
            ],
            'verify_identity' => [
                'description' => 'WhatsApp only. Confirm the phone-matched client with their account email or the last 4 digits of an invoice before any billing details are shared.',
                'access' => 'whatsapp_verify',
                'properties' => [
                    'method' => [
                        'type' => 'string',
                        'enum' => ['email', 'invoice_last4'],
                        'description' => 'Which check the customer agreed to.',
                    ],
                    'value' => [
                        'type' => 'string',
                        'description' => 'The email address, or exactly 4 digits.',
                    ],
                ],
                'required' => ['method', 'value'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function namesFor(Identity $identity): array
    {
        $names = [];
        foreach (self::all() as $name => $tool) {
            if (self::permits($tool['access'], $identity)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * OpenAI-compatible tool list for chat completions.
     *
     * @return list<array<string, mixed>>
     */
    public static function openAiTools(Identity $identity): array
    {
        $tools = [];
        foreach (self::namesFor($identity) as $name) {
            $tool = self::all()[$name];
            $properties = $tool['properties'] === [] ? new \stdClass() : $tool['properties'];
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $tool['description'],
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $tool['required'],
                        'additionalProperties' => false,
                    ],
                ],
            ];
        }
        return $tools;
    }

    public static function permits(string $access, Identity $identity): bool
    {
        return match ($access) {
            'public' => true,
            'account' => $identity->canUseAccountTools(),
            'billing' => $identity->canViewBilling(),
            'whatsapp_verify' => $identity->channel === 'whatsapp'
                && $identity->level !== IdentityLevel::Verified
                && ($identity->level === IdentityLevel::Linked || $identity->phoneCandidateIds !== []),
            default => false,
        };
    }
}
