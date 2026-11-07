<?php

namespace GlpiPlugin\Telegram;

use Throwable;

final class Installer
{
    public static function install(): bool
    {
        global $DB;

        $queries = [
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_destinations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                alias VARCHAR(120) NOT NULL,
                chat_id VARCHAR(64) NOT NULL,
                users_id INT UNSIGNED NULL,
                chat_type VARCHAR(20) NOT NULL DEFAULT 'private',
                is_active TINYINT NOT NULL DEFAULT 1,
                date_creation TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_alias (alias),
                KEY idx_active (is_active),
                KEY idx_user (users_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_subscriptions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `source` VARCHAR(80) NOT NULL,
                `event` VARCHAR(120) NOT NULL,
                destination_id INT UNSIGNED NOT NULL,
                is_active TINYINT NOT NULL DEFAULT 1,
                date_creation TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_subscription (`source`, `event`, destination_id),
                KEY idx_event_active (`source`, `event`, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_configs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                config_key VARCHAR(80) NOT NULL,
                config_value MEDIUMTEXT NULL,
                date_mod TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_config_key (config_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_delivery_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                queue_id BIGINT UNSIGNED NOT NULL,
                attempt_number SMALLINT UNSIGNED NOT NULL,
                result_status VARCHAR(16) NOT NULL,
                telegram_message_id VARCHAR(64) NULL,
                error_message VARCHAR(500) NULL,
                date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_queue (queue_id),
                KEY idx_date (date_creation),
                KEY idx_status (result_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_queue (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `source` VARCHAR(80) NOT NULL,
                `event` VARCHAR(120) NOT NULL,
                subject VARCHAR(180) NOT NULL,
                `message` TEXT NOT NULL,
                destination_id INT UNSIGNED NOT NULL,
                chat_id VARCHAR(64) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at DATETIME NOT NULL,
                processing_at DATETIME NULL,
                telegram_message_id VARCHAR(64) NULL,
                last_error VARCHAR(500) NULL,
                deduplication_key VARCHAR(191) NULL,
                date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                date_sent DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_dedup (destination_id, deduplication_key),
                KEY idx_delivery (status, next_attempt_at),
                KEY idx_source_event (`source`, `event`),
                KEY idx_processing (status, processing_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        try {
            foreach ($queries as $query) {
                if ($DB->doQuery($query) === false) {
                    return false;
                }
            }
            if ($DB->tableExists('glpi_plugin_telegram_destinations')
                && !$DB->fieldExists('glpi_plugin_telegram_destinations', 'users_id')
            ) {
                if ($DB->doQuery(
                    'ALTER TABLE glpi_plugin_telegram_destinations
                     ADD COLUMN users_id INT UNSIGNED NULL AFTER chat_id,
                     ADD KEY idx_user (users_id)'
                ) === false) {
                    return false;
                }
            }
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }
}
