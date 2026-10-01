<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Phone;

use InvalidArgumentException;

/**
 * Normalises phone numbers so WHMCS values such as "09876543210" match
 * WhatsApp values such as "+91 98765 43210".
 */
final class PhoneNormalizer
{
    public function __construct(private readonly string $defaultCountryCode = '91')
    {
        if (preg_match('/^\d{1,4}$/', $this->defaultCountryCode) !== 1) {
            throw new InvalidArgumentException('Default country code must be 1 to 4 digits.');
        }
    }

    public function defaultCountryCode(): string
    {
        return $this->defaultCountryCode;
    }

    /**
     * Digits only, with a country code when one can be inferred.
     * Empty when the input has no digits.
     */
    public function canonicalize(string $input): string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return '';
        }
        $international = str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($trimmed, '00')) {
            $digits = substr($digits, 2);
            $international = true;
        }
        if ($digits === '') {
            return '';
        }
        if ($international) {
            $digits = $this->stripTrunkZeroAfterCountry($digits);
            return ltrim($digits, '0');
        }
        if (str_starts_with($digits, '0')) {
            $national = ltrim($digits, '0');
            return $national === '' ? '' : $this->defaultCountryCode . $national;
        }
        if ($this->alreadyHasDefaultCountry($digits)) {
            return $digits;
        }
        if (strlen($digits) >= 8 && strlen($digits) <= 12) {
            return $this->defaultCountryCode . $digits;
        }
        return $digits;
    }

    public function matches(string $left, string $right): bool
    {
        $a = $this->canonicalize($left);
        $b = $this->canonicalize($right);
        if ($a === '' || $b === '' || strlen($a) < 8 || strlen($b) < 8) {
            return false;
        }
        if (hash_equals($a, $b)) {
            return true;
        }
        $shorter = strlen($a) < strlen($b) ? $a : $b;
        $longer = strlen($a) < strlen($b) ? $b : $a;
        $difference = strlen($longer) - strlen($shorter);
        // One side may still be a national number if the default country was not applied.
        return $difference >= 1
            && $difference <= 4
            && strlen($shorter) >= 8
            && str_ends_with($longer, $shorter);
    }

    /**
     * Split into the fields Interakt's documented template API expects.
     * Returns null when the number does not start with the configured country code.
     *
     * @return array{countryCode: string, phoneNumber: string}|null
     */
    public function split(string $input): ?array
    {
        $canonical = $this->canonicalize($input);
        $code = $this->defaultCountryCode;
        if ($canonical === '' || !str_starts_with($canonical, $code)) {
            return null;
        }
        $national = substr($canonical, strlen($code));
        if ($national === '' || strlen($national) < 6 || strlen($national) > 12) {
            return null;
        }
        return [
            'countryCode' => '+' . $code,
            'phoneNumber' => $national,
        ];
    }

    private function stripTrunkZeroAfterCountry(string $digits): string
    {
        $prefix = $this->defaultCountryCode . '0';
        if (str_starts_with($digits, $prefix)) {
            return $this->defaultCountryCode . substr($digits, strlen($prefix));
        }
        return $digits;
    }

    private function alreadyHasDefaultCountry(string $digits): bool
    {
        $code = $this->defaultCountryCode;
        if (!str_starts_with($digits, $code)) {
            return false;
        }
        $national = substr($digits, strlen($code));
        return strlen($national) >= 8 && strlen($national) <= 12;
    }
}
