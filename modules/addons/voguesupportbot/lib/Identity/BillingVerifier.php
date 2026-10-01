<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Identity;

/**
 * Extra WhatsApp check before billing details are revealed.
 * A match must identify exactly one of the phone-matched candidates.
 */
final class BillingVerifier
{
    /**
     * @param list<array{id: int, email: string, invoice_labels: list<string>}> $candidates
     */
    public static function match(string $method, string $value, array $candidates): ?int
    {
        $matched = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['id'];
            if ($method === 'email' && self::emailMatches($value, $candidate['email'])) {
                $matched[] = $id;
            }
            if ($method === 'invoice_last4' && self::invoiceLast4Matches($value, $candidate['invoice_labels'])) {
                $matched[] = $id;
            }
        }
        $matched = array_values(array_unique($matched));
        return count($matched) === 1 ? $matched[0] : null;
    }

    public static function emailMatches(string $provided, string $onFile): bool
    {
        $provided = strtolower(trim($provided));
        $onFile = strtolower(trim($onFile));
        if ($provided === '' || $onFile === '' || strlen($provided) > 200 || strlen($onFile) > 200) {
            return false;
        }
        return hash_equals($onFile, $provided);
    }

    /**
     * @param list<string> $labels Invoice ids or invoice numbers.
     */
    public static function invoiceLast4Matches(string $provided, array $labels): bool
    {
        $provided = preg_replace('/\s+/', '', $provided) ?? '';
        if (preg_match('/^\d{4}$/', $provided) !== 1) {
            return false;
        }
        foreach ($labels as $label) {
            $digits = preg_replace('/\D+/', '', $label) ?? '';
            if (strlen($digits) >= 4 && str_ends_with($digits, $provided)) {
                return true;
            }
        }
        return false;
    }
}
