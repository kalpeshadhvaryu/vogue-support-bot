<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Knowledge;

/**
 * Turns the GetProducts pricing map into a short public price line.
 * WHMCS uses -1.00 for billing cycles that are not offered.
 */
final class ProductSummary
{
    /**
     * @param array<mixed> $pricing
     */
    public static function pricing(array $pricing): string
    {
        $lines = [];
        foreach ($pricing as $currency => $cycles) {
            if (!is_string($currency) || !is_array($cycles)) {
                continue;
            }
            $parts = [];
            foreach (['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'] as $cycle) {
                if (!isset($cycles[$cycle]) || !is_scalar($cycles[$cycle])) {
                    continue;
                }
                $amount = (string) $cycles[$cycle];
                if ($amount === '' || !is_numeric($amount) || (float) $amount < 0) {
                    continue;
                }
                $prefix = isset($cycles['prefix']) && is_scalar($cycles['prefix']) ? (string) $cycles['prefix'] : '';
                $parts[] = $cycle . ' ' . $prefix . $amount;
            }
            if ($parts !== []) {
                $lines[] = $currency . ': ' . implode(', ', $parts);
            }
        }
        return implode(' | ', $lines);
    }
}
