<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Channel;

use VogueHosting\SupportBot\App;
use VogueHosting\SupportBot\Http\CurlHttpClient;
use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;
use VogueHosting\SupportBot\Interakt\InboundMessage;
use VogueHosting\SupportBot\Interakt\InteraktClient;
use VogueHosting\SupportBot\Logging\WhmcsLogger;
use VogueHosting\SupportBot\Phone\PhoneMatcher;
use VogueHosting\SupportBot\Phone\PhoneNormalizer;
use VogueHosting\SupportBot\Security\SessionWindow;
use VogueHosting\SupportBot\Security\WebhookSignature;
use VogueHosting\SupportBot\Storage\ConversationStore;
use VogueHosting\SupportBot\Storage\RateLimitStore;
use VogueHosting\SupportBot\Storage\Schema;
use VogueHosting\SupportBot\Storage\WebhookEventStore;
use VogueHosting\SupportBot\Whmcs\LocalApiGateway;

final class WhatsAppEndpoint
{
    public static function handle(): void
    {
        ini_set('display_errors', '0');
        $raw = file_get_contents('php://input');
        $raw = is_string($raw) ? $raw : '';
        if (strlen($raw) > 1000000) {
            self::status(413);
            return;
        }
        $settings = App::settings();
        $signature = self::header('Interakt-Signature');
        if (!WebhookSignature::verify($settings->interaktWebhookSecret(), $raw, $signature)) {
            (new WhmcsLogger())->activity('Rejected an Interakt webhook with a bad signature.');
            self::status(401);
            return;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            self::status(400);
            return;
        }
        $inbound = InboundMessage::fromPayload($payload);
        if ($inbound === null) {
            self::status(200);
            echo '{"ok":true}';
            return;
        }
        try {
            Schema::ensure();
            if (!(new WebhookEventStore())->claim($inbound->messageId)) {
                self::status(200);
                echo '{"ok":true}';
                return;
            }
        } catch (\Throwable $exception) {
            (new WhmcsLogger())->activity('WhatsApp webhook storage failed: ' . $exception->getMessage());
            self::status(500);
            return;
        }
        if (!$settings->whatsappEnabled()) {
            self::status(200);
            echo '{"ok":true}';
            return;
        }
        self::acknowledge();
        try {
            self::process($inbound);
        } catch (\Throwable $exception) {
            (new WhmcsLogger())->activity('WhatsApp processing failed: ' . $exception->getMessage());
        }
    }

    private static function process(InboundMessage $inbound): void
    {
        $settings = App::settings();
        $logger = new WhmcsLogger();
        $phones = new PhoneNormalizer($settings->defaultCountryCode());
        $canonical = $phones->canonicalize($inbound->phone);
        if ($canonical === '') {
            return;
        }
        $store = new ConversationStore();
        if (!(new RateLimitStore())->allow('wa:' . $canonical, $settings->rateLimit(), $settings->rateWindowSeconds())) {
            $logger->activity('WhatsApp rate limit for a sender.');
            return;
        }
        $receivedAt = $inbound->receivedAtUnix ?? time();
        $sessionOpen = SessionWindow::isOpen($receivedAt, time());
        $gateway = new LocalApiGateway($settings->apiAdminUsername(), $phones, $logger);
        $candidates = (new PhoneMatcher($phones))->matchingIds($inbound->phone, $gateway->findClientsByPhone($inbound->phone));
        if (count($candidates) > 5) {
            $candidates = [];
        }
        $text = trim($inbound->text);
        $reset = preg_match('/^(new chat|reset)$/i', $text) === 1;
        $conversation = $reset ? null : $store->findLatestByExternal('whatsapp', $canonical);
        if ($conversation === null) {
            $created = $store->create('whatsapp', null, $canonical, 'anonymous', false);
            $conversation = $created['conversation'];
        }
        if ($reset) {
            self::deliver($settings, $phones, $inbound->phone, 'Started a new chat. How can I help?', $sessionOpen, $conversation->publicId, $logger);
            return;
        }
        $identity = self::identity($conversation, $canonical, $candidates, $gateway, $phones, $store);
        $store->markCustomerMessage($conversation->id, gmdate('Y-m-d H:i:s', $receivedAt));
        if (strcasecmp($inbound->contentType, 'Text') !== 0 && $text === '') {
            self::deliver($settings, $phones, $inbound->phone, 'Please send a text message so I can help.', $sessionOpen, $conversation->publicId, $logger);
            return;
        }
        $reply = App::service()->reply($conversation, $identity, $text);
        self::deliver($settings, $phones, $inbound->phone, $reply->text, $sessionOpen, $conversation->publicId, $logger);
    }

    /**
     * @param list<int> $candidates
     */
    private static function identity(
        \VogueHosting\SupportBot\Storage\Conversation $conversation,
        string $canonical,
        array $candidates,
        LocalApiGateway $gateway,
        PhoneNormalizer $phones,
        ConversationStore $store,
    ): Identity {
        if ($conversation->identityLevel === 'verified' && $conversation->clientId !== null) {
            $client = $gateway->getClient($conversation->clientId);
            if ($client !== null && $phones->matches($canonical, $client['phonenumber'])) {
                return new Identity(IdentityLevel::Verified, 'whatsapp', $conversation->clientId, $canonical, [$conversation->clientId]);
            }
        }
        if (count($candidates) === 1) {
            $store->setIdentity($conversation->id, $candidates[0], 'linked');
            return new Identity(IdentityLevel::Linked, 'whatsapp', $candidates[0], $canonical, $candidates);
        }
        if ($conversation->clientId !== null || $conversation->identityLevel !== 'anonymous') {
            $store->setIdentity($conversation->id, null, 'anonymous');
        }
        return new Identity(IdentityLevel::Anonymous, 'whatsapp', null, $canonical, $candidates);
    }

    private static function deliver(
        \VogueHosting\SupportBot\Settings $settings,
        PhoneNormalizer $phones,
        string $rawPhone,
        string $message,
        bool $sessionOpen,
        string $publicId,
        WhmcsLogger $logger,
    ): void {
        if ($settings->interaktApiKey() === '') {
            $logger->activity('WhatsApp reply skipped because the Interakt API key is empty.');
            return;
        }
        $result = (new InteraktClient(new CurlHttpClient()))->sendReply(
            $settings->interaktApiKey(),
            $phones,
            $rawPhone,
            $message,
            $sessionOpen,
            $settings->interaktTemplateName(),
            $settings->interaktTemplateLanguage(),
            'vsb:' . $publicId,
        );
        $logger->moduleCall('interakt.send', [
            'mode' => $result['mode'],
            'session_open' => $sessionOpen,
        ], ['ok' => $result['ok'], 'id' => $result['id'], 'error' => $result['error']], [$settings->interaktApiKey()]);
        if (!$result['ok']) {
            $logger->activity('WhatsApp reply failed: ' . $result['error']);
        }
    }

    private static function acknowledge(): void
    {
        self::status(200);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"ok":true}';
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        ignore_user_abort(true);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    private static function status(int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }

    private static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
