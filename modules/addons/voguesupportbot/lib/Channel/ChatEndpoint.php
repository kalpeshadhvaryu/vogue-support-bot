<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Channel;

use VogueHosting\SupportBot\App;
use VogueHosting\SupportBot\Http\JsonResponse;
use VogueHosting\SupportBot\Http\SystemUrl;
use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;
use VogueHosting\SupportBot\Security\ClientIp;
use VogueHosting\SupportBot\Security\CorsPolicy;
use VogueHosting\SupportBot\Security\SessionCsrf;
use VogueHosting\SupportBot\Storage\ConversationStore;
use VogueHosting\SupportBot\Storage\RateLimitStore;
use VogueHosting\SupportBot\Storage\Schema;

/**
 * Client-area chat uses the WHMCS session. The public website embed is
 * anonymous even if a browser happens to have a session cookie.
 */
final class ChatEndpoint
{
    public static function clientArea(): void
    {
        self::boot();
        $settings = App::settings();
        if (!$settings->clientAreaEnabled()) {
            JsonResponse::send(403, self::error('channel_disabled', 'Chat is not available right now.'));
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            JsonResponse::send(405, self::error('method_not_allowed', 'Send a message to start.'));
            return;
        }
        $systemOrigin = SystemUrl::origin();
        $origin = CorsPolicy::normalize(is_string($_SERVER['HTTP_ORIGIN'] ?? null) ? $_SERVER['HTTP_ORIGIN'] : '');
        if ($origin !== null && $systemOrigin !== null && !hash_equals($systemOrigin, $origin)) {
            JsonResponse::send(403, self::error('origin_rejected', 'This chat can only be used from the client area.'));
            return;
        }
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            JsonResponse::send(401, self::error('login_required', 'Please log in to the client area to chat about your services.'));
            return;
        }
        $clientId = self::sessionClientId();
        if ($clientId === null) {
            JsonResponse::send(401, self::error('login_required', 'Please log in to the client area to chat about your services.'));
            return;
        }
        $body = self::body();
        if (!SessionCsrf::verify($_SESSION, 'voguesupportbot_csrf', (string) ($body['csrf_token'] ?? ''))) {
            JsonResponse::send(403, self::error('csrf_rejected', 'Please refresh the page and try again.'));
            return;
        }
        if (!self::allow('client:' . $clientId, $settings->rateLimit(), $settings->rateWindowSeconds())) {
            JsonResponse::send(429, self::error('rate_limited', 'Please wait a few minutes and try again.'));
            return;
        }
        $store = new ConversationStore();
        $identity = new Identity(IdentityLevel::Verified, 'clientarea', $clientId);
        $publicId = (string) ($body['conversation_id'] ?? '');
        $conversation = self::loadOwned($store, $publicId, 'clientarea', $clientId);
        if ($conversation === null && $publicId !== '') {
            JsonResponse::send(403, self::error('conversation_rejected', 'Please start a new chat.'));
            return;
        }
        if ($conversation === null && (($body['action'] ?? 'message') === 'history')) {
            JsonResponse::send(200, ['ok' => true, 'messages' => []]);
            return;
        }
        if ($conversation === null) {
            $created = $store->create('clientarea', $clientId, 'client:' . $clientId, 'verified', false);
            $conversation = $created['conversation'];
        }
        self::respond($store, $conversation, $identity, $body, null);
    }

    public static function website(): void
    {
        self::boot();
        $settings = App::settings();
        $origins = $settings->allowedOrigins();
        $systemOrigin = SystemUrl::origin();
        if ($systemOrigin !== null) {
            $origins[] = $systemOrigin;
        }
        $origin = CorsPolicy::match(is_string($_SERVER['HTTP_ORIGIN'] ?? null) ? $_SERVER['HTTP_ORIGIN'] : null, $origins);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            if ($origin === null) {
                JsonResponse::empty(403, null);
                return;
            }
            JsonResponse::empty(204, $origin);
            return;
        }
        if (!$settings->websiteEnabled()) {
            JsonResponse::send(403, self::error('channel_disabled', 'Chat is not available right now.'), $origin);
            return;
        }
        if ($origin === null) {
            JsonResponse::send(403, self::error('origin_rejected', 'This site is not allowed to use the chat widget.'));
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            JsonResponse::send(405, self::error('method_not_allowed', 'Send a message to start.'), $origin);
            return;
        }
        $ip = ClientIp::resolve($_SERVER, $settings->trustProxy());
        if (!self::allow('ip:' . $ip, $settings->rateLimit(), $settings->rateWindowSeconds())) {
            JsonResponse::send(429, self::error('rate_limited', 'Please wait a few minutes and try again.'), $origin);
            return;
        }
        $body = self::body();
        $store = new ConversationStore();
        $identity = new Identity(IdentityLevel::Anonymous, 'website', null);
        $publicId = (string) ($body['conversation_id'] ?? '');
        $token = (string) ($body['conversation_token'] ?? '');
        $conversation = null;
        $issuedToken = null;
        if ($publicId !== '') {
            $existing = $store->findByPublicId($publicId);
            if ($existing === null || $existing->channel !== 'website' || !$existing->tokenMatches($token)) {
                JsonResponse::send(403, self::error('conversation_rejected', 'Please start a new chat.'), $origin);
                return;
            }
            $conversation = $existing;
        }
        if ($conversation === null && (($body['action'] ?? 'message') === 'history')) {
            JsonResponse::send(200, ['ok' => true, 'messages' => []], $origin);
            return;
        }
        if ($conversation === null) {
            $created = $store->create('website', null, 'ip:' . $ip, 'anonymous', true);
            $conversation = $created['conversation'];
            $issuedToken = $created['token'];
        }
        self::respond($store, $conversation, $identity, $body, $origin, $issuedToken);
    }

    private static function boot(): void
    {
        ini_set('display_errors', '0');
        Schema::ensure();
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function respond(
        ConversationStore $store,
        \VogueHosting\SupportBot\Storage\Conversation $conversation,
        Identity $identity,
        array $body,
        ?string $corsOrigin,
        ?string $issuedToken = null,
    ): void {
        $action = (string) ($body['action'] ?? 'message');
        if ($action === 'history') {
            $messages = [];
            foreach ($store->messages($conversation->id, false) as $message) {
                $messages[] = ['role' => $message['role'], 'content' => $message['content']];
            }
            JsonResponse::send(200, [
                'ok' => true,
                'conversation_id' => $conversation->publicId,
                'messages' => array_slice($messages, -30),
            ], $corsOrigin);
            return;
        }
        $message = $body['message'] ?? '';
        if (!is_string($message) || trim($message) === '') {
            JsonResponse::send(400, self::error('empty_message', 'Type a message to continue.'), $corsOrigin);
            return;
        }
        $reply = App::service()->reply($conversation, $identity, $message);
        $payload = [
            'ok' => true,
            'reply' => $reply->text,
            'conversation_id' => $conversation->publicId,
            'handoff' => $reply->handoff,
        ];
        if ($issuedToken !== null) {
            $payload['conversation_token'] = $issuedToken;
        }
        JsonResponse::send(200, $payload, $corsOrigin);
    }

    private static function loadOwned(ConversationStore $store, string $publicId, string $channel, int $clientId): ?\VogueHosting\SupportBot\Storage\Conversation
    {
        if ($publicId === '') {
            return null;
        }
        $existing = $store->findByPublicId($publicId);
        if ($existing === null || $existing->channel !== $channel || $existing->clientId !== $clientId) {
            return null;
        }
        return $existing;
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > 20000) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function allow(string $key, int $limit, int $window): bool
    {
        try {
            return (new RateLimitStore())->allow($key, $limit, $window);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function sessionClientId(): ?int
    {
        $uid = $_SESSION['uid'] ?? null;
        if (is_int($uid) && $uid > 0) {
            return $uid;
        }
        if (is_string($uid) && ctype_digit($uid) && (int) $uid > 0) {
            return (int) $uid;
        }
        return null;
    }

    /**
     * @return array{ok: false, error: string, reply: string}
     */
    private static function error(string $code, string $reply): array
    {
        return ['ok' => false, 'error' => $code, 'reply' => $reply];
    }
}
