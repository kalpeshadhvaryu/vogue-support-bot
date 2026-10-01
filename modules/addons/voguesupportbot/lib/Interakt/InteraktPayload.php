<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Interakt;

/**
 * Bodies for POST https://api.interakt.ai/v1/public/message/
 *
 * The template shape matches Interakt's published curl example
 * (countryCode, phoneNumber, type Template, template.name/languageCode/bodyValues).
 *
 * The session text shape follows the same endpoint and the type "Text" used in
 * inbound webhooks (message_content_type) and in the community PHP SDK
 * (data.message). TODO: confirm the Text body against Interakt's current
 * Postman collection before go-live. The public resource-center article only
 * prints the Template example.
 */
final class InteraktPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function sessionText(
        string $countryCode,
        string $phoneNumber,
        string $message,
        string $callbackData,
    ): array {
        return [
            'countryCode' => $countryCode,
            'phoneNumber' => $phoneNumber,
            'callbackData' => $callbackData,
            'type' => 'Text',
            'data' => [
                'message' => $message,
            ],
        ];
    }

    /**
     * Used when the number cannot be split into the configured country code.
     * fullPhoneNumber is accepted by the community SDK on this same endpoint.
     * TODO: confirm fullPhoneNumber against the official Postman collection.
     *
     * @return array<string, mixed>
     */
    public static function sessionTextFullNumber(string $fullPhoneNumber, string $message, string $callbackData): array
    {
        return [
            'fullPhoneNumber' => $fullPhoneNumber,
            'callbackData' => $callbackData,
            'type' => 'Text',
            'data' => [
                'message' => $message,
            ],
        ];
    }

    /**
     * The approved template must have exactly one body variable. That variable
     * receives the assistant reply, truncated to a template-safe length.
     *
     * @return array<string, mixed>
     */
    public static function template(
        string $countryCode,
        string $phoneNumber,
        string $templateName,
        string $languageCode,
        string $bodyValue,
        string $callbackData,
    ): array {
        return [
            'countryCode' => $countryCode,
            'phoneNumber' => $phoneNumber,
            'callbackData' => $callbackData,
            'type' => 'Template',
            'template' => [
                'name' => $templateName,
                'languageCode' => $languageCode,
                'bodyValues' => [$bodyValue],
            ],
        ];
    }
}
