<?php

if (!defined('WHMCS')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib/Autoload.php';

use VogueHosting\SupportBot\Admin\AdminPage;
use VogueHosting\SupportBot\Settings;
use VogueHosting\SupportBot\Storage\Schema;

function voguesupportbot_config(): array
{
    return [
        'name' => 'Vogue Support Bot',
        'description' => 'Grok-powered support assistant for the WHMCS client area, voguehosting.com, and WhatsApp via Interakt.',
        'version' => '1.0.0',
        'author' => 'Vogue Hosting',
        'language' => 'english',
        'fields' => [
            'xai_api_key' => [
                'FriendlyName' => 'xAI API key',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Stored only in WHMCS addon settings. Create one in the xAI console.',
            ],
            'xai_model' => [
                'FriendlyName' => 'Model',
                'Type' => 'text',
                'Size' => '40',
                'Default' => Settings::DEFAULT_MODEL,
                'Description' => 'Default grok-4.7, the current id in xAI\'s chat completions and function-calling docs. Change this when you want a different model.',
            ],
            'system_prompt' => [
                'FriendlyName' => 'Brand voice',
                'Type' => 'textarea',
                'Rows' => '6',
                'Cols' => '70',
                'Description' => 'Tone and company facts. Safety rules are added in code and are not overridden by this text.',
            ],
            'extra_context' => [
                'FriendlyName' => 'FAQ and extra context',
                'Type' => 'textarea',
                'Rows' => '8',
                'Cols' => '70',
                'Description' => 'Policies, plan notes, or answers that are not in the WHMCS knowledgebase. Treated as reference data, not as instructions to the model.',
            ],
            'widget_title' => [
                'FriendlyName' => 'Widget title',
                'Type' => 'text',
                'Size' => '40',
                'Default' => 'Vogue Hosting',
            ],
            'widget_greeting' => [
                'FriendlyName' => 'Widget greeting',
                'Type' => 'textarea',
                'Rows' => '3',
                'Cols' => '70',
                'Description' => 'First line shown in the chat panel.',
            ],
            'interakt_api_key' => [
                'FriendlyName' => 'Interakt API key',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Sent as Authorization: Basic. From Interakt Developer Settings.',
            ],
            'interakt_webhook_secret' => [
                'FriendlyName' => 'Interakt webhook secret',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'The secret you enter in Interakt when you save the webhook URL. Used to verify Interakt-Signature.',
            ],
            'interakt_template_name' => [
                'FriendlyName' => 'WhatsApp template name',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Approved template used only outside the 24-hour session window. It must have exactly one body variable, which receives the reply.',
            ],
            'interakt_template_language' => [
                'FriendlyName' => 'Template language',
                'Type' => 'text',
                'Size' => '10',
                'Default' => 'en',
            ],
            'allowed_origins' => [
                'FriendlyName' => 'Allowed website origins',
                'Type' => 'textarea',
                'Rows' => '4',
                'Cols' => '70',
                'Description' => 'One origin per line, including https, for example https://voguehosting.com. No wildcards. The WHMCS system URL is allowed in addition to this list.',
            ],
            'handoff_department' => [
                'FriendlyName' => 'Human handoff department ID',
                'Type' => 'text',
                'Size' => '10',
                'Description' => 'Support department id used for new tickets and human handoffs. Find it under Support > Support Departments.',
            ],
            'api_admin_username' => [
                'FriendlyName' => 'WHMCS API admin username',
                'Type' => 'text',
                'Size' => '30',
                'Description' => 'Admin user localAPI runs as. Needs permission to view clients, services, domains, invoices, and tickets, and to open and reply to tickets.',
            ],
            'enable_clientarea' => [
                'FriendlyName' => 'Enable client area chat',
                'Type' => 'yesno',
                'Description' => 'Injects the widget for logged-in clients.',
            ],
            'enable_website' => [
                'FriendlyName' => 'Enable website embed',
                'Type' => 'yesno',
                'Description' => 'Anonymous chat for voguehosting.com, and for guests in the client area.',
            ],
            'enable_whatsapp' => [
                'FriendlyName' => 'Enable WhatsApp',
                'Type' => 'yesno',
                'Description' => 'Processes Interakt message_received webhooks.',
            ],
            'max_tokens' => [
                'FriendlyName' => 'Max tokens per model call',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '4096',
                'Description' => 'Sent as max_tokens. Reasoning models spend some of this on reasoning, so keep it in the thousands.',
            ],
            'token_budget' => [
                'FriendlyName' => 'Token budget per conversation',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '12000',
            ],
            'rate_limit' => [
                'FriendlyName' => 'Messages per 10 minutes',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '12',
                'Description' => 'Per client, per website IP, and per WhatsApp number.',
            ],
            'default_country_code' => [
                'FriendlyName' => 'Default phone country code',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '91',
                'Description' => 'Used when a WHMCS phone number has no country code. Digits only, for example 91.',
            ],
            'kb_ttl_minutes' => [
                'FriendlyName' => 'Knowledge cache TTL (minutes)',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '60',
            ],
            'trust_proxy' => [
                'FriendlyName' => 'Trust X-Forwarded-For',
                'Type' => 'yesno',
                'Description' => 'Enable only when WHMCS is behind a trusted reverse proxy. Otherwise the client IP is REMOTE_ADDR.',
            ],
            'drop_tables' => [
                'FriendlyName' => 'Drop tables on deactivate',
                'Type' => 'yesno',
                'Description' => 'Leave off to keep conversations when the addon is deactivated. Turn on only if you want the tables removed.',
            ],
        ],
    ];
}

function voguesupportbot_activate(): array
{
    try {
        Schema::ensure();
        return [
            'status' => 'success',
            'description' => 'Tables created. Add the API keys on the Configure tab, then turn on a channel.',
        ];
    } catch (Throwable $exception) {
        return [
            'status' => 'error',
            'description' => 'Could not create tables: ' . $exception->getMessage(),
        ];
    }
}

function voguesupportbot_deactivate(array $vars): array
{
    if (($vars['drop_tables'] ?? '') === 'on') {
        try {
            Schema::drop();
        } catch (Throwable $exception) {
            return [
                'status' => 'error',
                'description' => 'Could not drop tables: ' . $exception->getMessage(),
            ];
        }
        return [
            'status' => 'success',
            'description' => 'Settings removed and conversation tables dropped.',
        ];
    }
    return [
        'status' => 'success',
        'description' => 'Settings removed. Conversation tables were kept.',
    ];
}

function voguesupportbot_upgrade(array $vars): void
{
    Schema::ensure();
}

function voguesupportbot_output(array $vars): void
{
    AdminPage::render($vars);
}
