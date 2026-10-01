<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Tools;

use VogueHosting\SupportBot\Identity\Identity;
use VogueHosting\SupportBot\Identity\IdentityLevel;

/**
 * Rejects tool arguments the model is not allowed to choose, including any
 * attempt to name a client id.
 */
final class ToolArgumentValidator
{
    /** @var list<string> */
    private const FORBIDDEN = [
        'client_id', 'clientid', 'userid', 'user_id', 'admin', 'password', 'api_key', 'secret',
    ];

    public function validate(string $toolName, array $arguments, Identity $identity): ValidationResult
    {
        $catalog = ToolCatalog::all();
        if (!isset($catalog[$toolName])) {
            return ValidationResult::failure('unknown_tool');
        }
        if (!in_array($toolName, ToolCatalog::namesFor($identity), true)) {
            return ValidationResult::failure('tool_not_permitted');
        }
        foreach ($arguments as $key => $value) {
            if (!is_string($key) || in_array(strtolower($key), self::FORBIDDEN, true)) {
                return ValidationResult::failure('forbidden_argument');
            }
        }
        $tool = $catalog[$toolName];
        $clean = [];
        foreach ($arguments as $key => $value) {
            if (!isset($tool['properties'][$key])) {
                return ValidationResult::failure('unexpected_argument');
            }
            $parsed = $this->parseProperty($tool['properties'][$key], $value);
            if ($parsed === null) {
                return ValidationResult::failure('invalid_argument');
            }
            $clean[$key] = $parsed;
        }
        foreach ($tool['required'] as $required) {
            if (!array_key_exists($required, $clean) || $clean[$required] === '') {
                return ValidationResult::failure('missing_argument');
            }
        }
        $extra = $this->identityRules($toolName, $clean, $identity);
        if ($extra !== null) {
            return ValidationResult::failure($extra);
        }
        return ValidationResult::success($clean);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function parseProperty(array $schema, mixed $value): mixed
    {
        $type = $schema['type'] ?? 'string';
        if ($type === 'integer') {
            if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
                $value = (int) $value;
            }
            if (!is_int($value) || $value < 0) {
                return null;
            }
            if (isset($schema['minimum']) && $value < (int) $schema['minimum']) {
                return null;
            }
            if (isset($schema['maximum']) && $value > (int) $schema['maximum']) {
                return null;
            }
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return null;
        }
        if (mb_strlen($value) > 4000) {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function identityRules(string $toolName, array $arguments, Identity $identity): ?string
    {
        if ($toolName === 'open_ticket') {
            $subject = $this->stringArg($arguments, 'subject');
            $message = $this->stringArg($arguments, 'message');
            if (mb_strlen($subject) < 3 || mb_strlen($subject) > 180 || mb_strlen($message) < 1 || mb_strlen($message) > 4000) {
                return 'invalid_argument';
            }
            if ($identity->level === IdentityLevel::Anonymous) {
                $name = $this->stringArg($arguments, 'name');
                $email = strtolower($this->stringArg($arguments, 'email'));
                if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    return 'anonymous_contact_required';
                }
            }
        }
        if ($toolName === 'reply_ticket') {
            $message = $this->stringArg($arguments, 'message');
            if (mb_strlen($message) < 1 || mb_strlen($message) > 4000) {
                return 'invalid_argument';
            }
        }
        if ($toolName === 'handoff_to_human' && mb_strlen($this->stringArg($arguments, 'reason')) > 500) {
            return 'invalid_argument';
        }
        if ($toolName === 'search_knowledge' && mb_strlen($this->stringArg($arguments, 'query')) > 300) {
            return 'invalid_argument';
        }
        if ($toolName === 'verify_identity') {
            $method = $this->stringArg($arguments, 'method');
            $value = $this->stringArg($arguments, 'value');
            if ($method === 'email' && filter_var(strtolower($value), FILTER_VALIDATE_EMAIL) === false) {
                return 'invalid_argument';
            }
            if ($method === 'invoice_last4' && preg_match('/^\d{4}$/', preg_replace('/\s+/', '', $value) ?? '') !== 1) {
                return 'invalid_argument';
            }
        }
        if ($toolName === 'list_tickets' && isset($arguments['limit']) && ((int) $arguments['limit'] < 1 || (int) $arguments['limit'] > 15)) {
            return 'invalid_argument';
        }
        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function stringArg(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? '';
        return is_string($value) ? $value : '';
    }
}
