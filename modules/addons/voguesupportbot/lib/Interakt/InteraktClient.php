<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Interakt;

use VogueHosting\SupportBot\Http\HttpClient;
use VogueHosting\SupportBot\Phone\PhoneNormalizer;
use VogueHosting\SupportBot\Text;

final class InteraktClient
{
    public const ENDPOINT = 'https://api.interakt.ai/v1/public/message/';

    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Sends a session message when the 24-hour window is open, otherwise a template.
     *
     * @return array{ok: bool, id: string, mode: string, error: string}
     */
    public function sendReply(
        string $apiKey,
        PhoneNormalizer $phones,
        string $rawPhone,
        string $message,
        bool $sessionOpen,
        string $templateName,
        string $templateLanguage,
        string $callbackData,
    ): array {
        $message = Text::limit($message, 3500);
        $split = $phones->split($rawPhone);
        if ($sessionOpen) {
            $payload = $split !== null
                ? InteraktPayload::sessionText($split['countryCode'], $split['phoneNumber'], $message, $callbackData)
                : InteraktPayload::sessionTextFullNumber($phones->canonicalize($rawPhone), $message, $callbackData);
            $mode = 'session';
        } else {
            if ($templateName === '' || $split === null) {
                return [
                    'ok' => false,
                    'id' => '',
                    'mode' => 'template',
                    'error' => 'The 24-hour WhatsApp window is closed and a template reply is not configured for this number.',
                ];
            }
            $payload = InteraktPayload::template(
                $split['countryCode'],
                $split['phoneNumber'],
                $templateName,
                $templateLanguage,
                Text::limit($message, 900),
                $callbackData,
            );
            $mode = 'template';
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'id' => '', 'mode' => $mode, 'error' => 'Could not encode the Interakt request.'];
        }
        $response = $this->http->post(self::ENDPOINT, [
            'Content-Type: application/json',
            'Authorization: Basic ' . $apiKey,
            'User-Agent: VogueSupportBot/1.0',
        ], $body, 15);
        if ($response->error !== null || $response->status >= 400) {
            return ['ok' => false, 'id' => '', 'mode' => $mode, 'error' => $response->error ?? ('Interakt HTTP ' . $response->status)];
        }
        $decoded = json_decode($response->body, true);
        $id = is_array($decoded) && isset($decoded['id']) && is_string($decoded['id']) ? $decoded['id'] : '';
        $ok = is_array($decoded) && ($decoded['result'] ?? false) === true;
        return [
            'ok' => $ok,
            'id' => $id,
            'mode' => $mode,
            'error' => $ok ? '' : 'Interakt did not accept the message.',
        ];
    }
}
