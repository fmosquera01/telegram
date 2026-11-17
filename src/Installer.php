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
                parse_mode VARCHAR(16) NULL DEFAULT NULL,
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
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_ad_requests (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_uuid CHAR(36) NOT NULL,
                operation VARCHAR(32) NOT NULL,
                sam_account_name VARCHAR(64) NOT NULL,
                requested_by_users_id INT UNSIGNED NOT NULL,
                telegram_chat_id VARCHAR(64) NOT NULL,
                telegram_update_id BIGINT UNSIGNED NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending_confirmation',
                confirmation_token_hash CHAR(64) NULL,
                confirmation_expires_at DATETIME NULL,
                confirmed_at DATETIME NULL,
                agent_id VARCHAR(80) NULL,
                target_agent_id VARCHAR(64) NULL,
                lease_token_hash CHAR(64) NULL,
                lease_expires_at DATETIME NULL,
                attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                result_code VARCHAR(64) NULL,
                result_message VARCHAR(500) NULL,
                date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                date_mod DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                date_completed DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_request_uuid (request_uuid),
                UNIQUE KEY uniq_telegram_update (telegram_update_id),
                KEY idx_status_created (status, date_creation),
                KEY idx_sam_account (sam_account_name),
                KEY idx_requester (requested_by_users_id),
                KEY idx_confirmation_expiry (status, confirmation_expires_at),
                KEY idx_agent_lease (status, agent_id, lease_expires_at),
                KEY idx_target_agent_status (target_agent_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_ad_authorizations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                users_id INT UNSIGNED NOT NULL,
                can_view TINYINT NOT NULL DEFAULT 0,
                can_unlock TINYINT NOT NULL DEFAULT 0,
                is_active TINYINT NOT NULL DEFAULT 1,
                date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                date_mod DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user (users_id),
                KEY idx_active_permissions (is_active, can_view, can_unlock)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_ad_agents (
                agent_id VARCHAR(64) NOT NULL,
                name VARCHAR(120) NOT NULL,
                domain_controller VARCHAR(253) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                is_active TINYINT NOT NULL DEFAULT 1,
                date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                date_mod DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (agent_id),
                KEY idx_agent_status (status, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_ad_agent_nonces (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                agent_id VARCHAR(64) NOT NULL,
                nonce CHAR(32) NOT NULL,
                expires_at DATETIME NOT NULL,
                date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_agent_nonce (agent_id, nonce),
                KEY idx_nonce_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS glpi_plugin_telegram_ad_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id BIGINT UNSIGNED NOT NULL,
                actor_type VARCHAR(24) NOT NULL,
                actor_id VARCHAR(80) NULL,
                event VARCHAR(48) NOT NULL,
                details MEDIUMTEXT NULL,
                date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_request_date (request_id, date_creation),
                KEY idx_event_date (event, date_creation)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        try {
            foreach ($queries as $query) {
                if ($DB->doQuery($query) === false) {
                    return false;
                }
            }
            if ($DB->tableExists('glpi_plugin_telegram_queue')
                && !$DB->fieldExists('glpi_plugin_telegram_queue', 'parse_mode')
                && $DB->doQuery(
                    'ALTER TABLE glpi_plugin_telegram_queue ADD COLUMN parse_mode VARCHAR(16) NULL DEFAULT NULL AFTER `message`'
                ) === false
            ) {
                return false;
            }
            if ($DB->tableExists('glpi_plugin_telegram_ad_audit')
                && $DB->fieldExists('glpi_plugin_telegram_ad_audit', 'details')
                && $DB->doQuery('ALTER TABLE glpi_plugin_telegram_ad_audit MODIFY details MEDIUMTEXT NULL') === false
            ) {
                return false;
            }
            if ($DB->tableExists('glpi_plugin_telegram_ad_requests')
                && !$DB->fieldExists('glpi_plugin_telegram_ad_requests', 'telegram_update_id')
                && $DB->doQuery(
                    'ALTER TABLE glpi_plugin_telegram_ad_requests
                     ADD COLUMN telegram_update_id BIGINT UNSIGNED NULL AFTER telegram_chat_id,
                     ADD UNIQUE KEY uniq_telegram_update (telegram_update_id)'
                ) === false
            ) {
                return false;
            }
            if ($DB->tableExists('glpi_plugin_telegram_ad_requests')
                && !$DB->fieldExists('glpi_plugin_telegram_ad_requests', 'target_agent_id')
                && $DB->doQuery('ALTER TABLE glpi_plugin_telegram_ad_requests ADD COLUMN target_agent_id VARCHAR(64) NULL AFTER agent_id, ADD KEY idx_target_agent_status (target_agent_id, status)') === false
            ) {
                return false;
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
            if (!AgentRegistry::migrateLegacyAgent()) {
                return false;
            }
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }
}
