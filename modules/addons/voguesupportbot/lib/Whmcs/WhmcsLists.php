<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Whmcs;

/**
 * WHMCS localAPI returns one record as an associative array and many records
 * as a list. Callers should not have to remember which shape they got.
 */
final class WhmcsLists
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function asList(mixed $node): array
    {
        if (!is_array($node) || $node === []) {
            return [];
        }
        if (!array_is_list($node)) {
            return [$node];
        }
        $rows = [];
        foreach ($node as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
