<?php

declare(strict_types=1);

namespace VogueHosting\SupportBot\Storage;

final class Schema
{
    public const CONVERSATIONS = 'mod_voguesupportbot_conversations';
    public const MESSAGES = 'mod_voguesupportbot_messages';
    public const KNOWLEDGE = 'mod_voguesupportbot_kb_cache';
    public const RATE_LIMITS = 'mod_voguesupportbot_rate_limits';
    public const WEBHOOK_EVENTS = 'mod_voguesupportbot_webhook_events';

    public static function ensure(): void
    {
        $capsule = self::capsule();
        $capsule::statement(self::conversationsSql());
        $capsule::statement(self::messagesSql());
        $capsule::statement(self::knowledgeSql());
        $capsule::statement(self::rateLimitSql());
        $capsule::statement(self::webhookSql());
    }

    public static function drop(): void
    {
        $capsule = self::capsule();
        foreach ([self::MESSAGES, self::CONVERSATIONS, self::KNOWLEDGE, self::RATE_LIMITS, self::WEBHOOK_EVENTS] as $table) {
            $capsule::statement('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    private static function conversationsSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::CONVERSATIONS . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `public_id` CHAR(32) NOT NULL,
            `access_token_hash` CHAR(64) NOT NULL DEFAULT \'\',
            `channel` VARCHAR(16) NOT NULL,
            `client_id` INT UNSIGNED NULL,
            `external_key` VARCHAR(64) NOT NULL DEFAULT \'\',
            `identity_level` VARCHAR(24) NOT NULL DEFAULT \'anonymous\',
            `handoff_status` VARCHAR(16) NOT NULL DEFAULT \'none\',
            `handoff_ticket_id` INT UNSIGNED NULL,
            `handoff_ticket_tid` VARCHAR(32) NULL,
            `last_customer_at` DATETIME NULL,
            `token_usage` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `public_id` (`public_id`),
            KEY `channel_external` (`channel`, `external_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private static function messagesSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::MESSAGES . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `conversation_id` INT UNSIGNED NOT NULL,
            `role` VARCHAR(16) NOT NULL,
            `content` MEDIUMTEXT NOT NULL,
            `tool_name` VARCHAR(64) NOT NULL DEFAULT \'\',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `conversation_id` (`conversation_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private static function knowledgeSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::KNOWLEDGE . '` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `source` VARCHAR(24) NOT NULL,
            `source_key` VARCHAR(64) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `body` MEDIUMTEXT NOT NULL,
            `url` VARCHAR(512) NOT NULL DEFAULT \'\',
            `refreshed_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `source_item` (`source`, `source_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private static function rateLimitSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::RATE_LIMITS . '` (
            `bucket_key` VARCHAR(128) NOT NULL,
            `window_start` INT UNSIGNED NOT NULL,
            `hits` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`bucket_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private static function webhookSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::WEBHOOK_EVENTS . '` (
            `event_id` VARCHAR(80) NOT NULL,
            `received_at` DATETIME NOT NULL,
            PRIMARY KEY (`event_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * @return class-string
     */
    private static function capsule(): string
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            throw new \RuntimeException('WHMCS database is not available.');
        }
        return \WHMCS\Database\Capsule::class;
    }
}
