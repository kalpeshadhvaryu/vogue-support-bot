<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot;

final class Text
{
    public static function plain(string $html, int $max): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
        return self::limit(trim($text), $max);
    }

    public static function limit(string $text, int $max): string
    {
        if ($max < 1) {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        if ($max === 1) {
            return '…';
        }
        return mb_substr($text, 0, $max - 1) . '…';
    }

    public static function singleLine(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return self::limit($text, $max);
    }
}
