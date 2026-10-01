<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot;

/**
 * Addon settings as stored by WHMCS in tbladdonmodules.
 * Secrets are read from that table at runtime and are never hard-coded.
 *
 * Default model id: grok-4.7. xAI's function-calling docs and the Grok 4.7
 * model card (checked October 2026) use this id on the chat completions API.
 */
final class Settings
{
    public const DEFAULT_MODEL = 'grok-4.7';

    public const DEFAULT_BRAND_PROMPT = <<<'PROMPT'
You are the customer support assistant for Vogue Hosting, a web hosting company. Be concise, warm, and practical. Use the knowledge you are given for prices and policies. If you do not know something, say so and offer to open a ticket. Do not claim to have changed an account unless a tool result says the change succeeded.
PROMPT;

    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(private readonly array $raw)
    {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    public function xaiApiKey(): string
    {
        return $this->string('xai_api_key');
    }

    public function model(): string
    {
        $model = $this->string('xai_model');
        if ($model === '' || preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $model) !== 1) {
            return self::DEFAULT_MODEL;
        }
        return $model;
    }

    public function brandPrompt(): string
    {
        $prompt = $this->string('system_prompt');
        return $prompt !== '' ? $prompt : self::DEFAULT_BRAND_PROMPT;
    }

    public function extraContext(): string
    {
        return Text::limit($this->string('extra_context'), 4000);
    }

    public function widgetTitle(): string
    {
        $title = $this->string('widget_title');
        return $title !== '' ? Text::limit($title, 80) : 'Vogue Hosting';
    }

    public function widgetGreeting(): string
    {
        $greeting = $this->string('widget_greeting');
        if ($greeting !== '') {
            return Text::limit($greeting, 400);
        }
        return 'Hi, I can help with plans and common questions. Log in to the client area if you want me to look at your services.';
    }

    public function interaktApiKey(): string
    {
        return $this->string('interakt_api_key');
    }

    public function interaktWebhookSecret(): string
    {
        return $this->string('interakt_webhook_secret');
    }

    public function interaktTemplateName(): string
    {
        return $this->string('interakt_template_name');
    }

    public function interaktTemplateLanguage(): string
    {
        $language = $this->string('interakt_template_language');
        if ($language === '' || preg_match('/^[A-Za-z0-9_-]{2,12}$/', $language) !== 1) {
            return 'en';
        }
        return $language;
    }

    /**
     * @return list<string>
     */
    public function allowedOrigins(): array
    {
        $raw = str_replace([",", "\r"], ["\n", ''], $this->string('allowed_origins'));
        $origins = [];
        foreach (preg_split('/\s+/', $raw) ?: [] as $origin) {
            if ($origin !== '') {
                $origins[] = $origin;
            }
        }
        return $origins;
    }

    public function handoffDepartmentId(): int
    {
        $id = (int) $this->string('handoff_department');
        return $id > 0 ? $id : 0;
    }

    public function apiAdminUsername(): string
    {
        return $this->string('api_admin_username');
    }

    public function clientAreaEnabled(): bool
    {
        return $this->yes('enable_clientarea');
    }

    public function websiteEnabled(): bool
    {
        return $this->yes('enable_website');
    }

    public function whatsappEnabled(): bool
    {
        return $this->yes('enable_whatsapp');
    }

    public function maxTokens(): int
    {
        return $this->int('max_tokens', 4096, 256, 16000);
    }

    public function tokenBudget(): int
    {
        return $this->int('token_budget', 12000, 1000, 200000);
    }

    public function rateLimit(): int
    {
        return $this->int('rate_limit', 12, 1, 200);
    }

    public function rateWindowSeconds(): int
    {
        return 600;
    }

    public function defaultCountryCode(): string
    {
        $code = preg_replace('/\D+/', '', $this->string('default_country_code')) ?? '';
        if ($code === '' || strlen($code) > 4) {
            return '91';
        }
        return $code;
    }

    public function trustProxy(): bool
    {
        return $this->yes('trust_proxy');
    }

    public function dropTablesOnDeactivate(): bool
    {
        return $this->yes('drop_tables');
    }

    public function knowledgeTtlSeconds(): int
    {
        return $this->int('kb_ttl_minutes', 60, 5, 1440) * 60;
    }

    private function string(string $key): string
    {
        $value = $this->raw[$key] ?? '';
        return is_string($value) ? trim($value) : (is_scalar($value) ? trim((string) $value) : '');
    }

    private function yes(string $key): bool
    {
        return $this->string($key) === 'on';
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $raw = $this->string($key);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return $default;
        }
        return max($min, min($max, (int) $raw));
    }
}
