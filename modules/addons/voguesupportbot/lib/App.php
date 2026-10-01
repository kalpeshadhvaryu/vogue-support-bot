<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot;

use VogueHosting\SupportBot\Channel\ConversationService;
use VogueHosting\SupportBot\Http\CurlHttpClient;
use VogueHosting\SupportBot\Knowledge\KnowledgeCache;
use VogueHosting\SupportBot\Knowledge\KnowledgeSelector;
use VogueHosting\SupportBot\Llm\ChatOrchestrator;
use VogueHosting\SupportBot\Llm\GrokClient;
use VogueHosting\SupportBot\Logging\WhmcsLogger;
use VogueHosting\SupportBot\Phone\PhoneNormalizer;
use VogueHosting\SupportBot\Storage\ConversationStore;
use VogueHosting\SupportBot\Tools\ToolArgumentValidator;
use VogueHosting\SupportBot\Whmcs\LocalApiGateway;

final class App
{
    public static function settings(): Settings
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return Settings::fromArray([]);
        }
        $rows = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'voguesupportbot')
            ->get(['setting', 'value']);
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->setting] = (string) $row->value;
        }
        return Settings::fromArray($map);
    }

    public static function service(?Settings $settings = null): ConversationService
    {
        $settings ??= self::settings();
        $logger = new WhmcsLogger();
        $gateway = new LocalApiGateway(
            $settings->apiAdminUsername(),
            new PhoneNormalizer($settings->defaultCountryCode()),
            $logger,
        );
        $selector = new KnowledgeSelector();
        return new ConversationService(
            $settings,
            new ConversationStore(),
            new KnowledgeCache($gateway),
            new ChatOrchestrator(new GrokClient(new CurlHttpClient()), new ToolArgumentValidator(), $selector),
            $selector,
            $logger,
            $gateway,
        );
    }
}
