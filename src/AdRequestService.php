<?php

namespace GlpiPlugin\Telegram;

use RuntimeException;

final class AdRequestService
{
    private const CONFIRMATION_TTL_MINUTES = 5;

    /**
     * Create an AD request only for an active private Telegram destination
     * linked to an active GLPI user with the required explicit permission.
     *
     * @return array{request_uuid:string,status:string,confirmation_token:?string,expires_in:int}
     */
    public static function createRequest(
        string $operation,
        string $samAccountName,
        int $usersId,
        string $chatId
    ): array {
        global $DB;

        if (!in_array($operation, ['account_status', 'unlock_account'], true)) {
            throw new RuntimeException('Operación AD no permitida.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', $samAccountName)) {
            throw new RuntimeException('Nombre de cuenta AD no válido.');
        }
        if ($usersId < 1 || !preg_match('/^\d{1,20}$/', $chatId)) {
            throw new RuntimeException('La identidad de Telegram o GLPI no es válida.');
        }

        $safeChatId = $DB->escape($chatId);
        $destination = $DB->doQuery(
            "SELECT d.id
             FROM glpi_plugin_telegram_destinations d
             INNER JOIN glpi_users u ON u.id = d.users_id
             WHERE d.chat_id = '{$safeChatId}'
               AND d.users_id = {$usersId}
               AND d.chat_type = 'private'
               AND d.is_active = 1
               AND u.is_active = 1
               AND u.is_deleted = 0
             LIMIT 1"
        );
        if (!$destination || !$DB->fetchAssoc($destination)) {
            throw new RuntimeException('Chat privado no vinculado o inactivo.');
        }

        $permission = $DB->doQuery(
            "SELECT a.can_view, a.can_unlock
             FROM glpi_plugin_telegram_ad_authorizations a
             INNER JOIN glpi_users u ON u.id = a.users_id
             WHERE a.users_id = {$usersId}
               AND a.is_active = 1
               AND u.is_active = 1
               AND u.is_deleted = 0
             LIMIT 1"
        );
        $rights = $permission ? $DB->fetchAssoc($permission) : false;
        $allowed = $rights && (
            ($operation === 'account_status' && (int) $rights['can_view'] === 1)
            || ($operation === 'unlock_account' && (int) $rights['can_unlock'] === 1)
        );
        if (!$allowed) {
            throw new RuntimeException('El usuario GLPI no tiene autorización para esta operación AD.');
        }

        $uuidBytes = random_bytes(16);
        $uuidBytes[6] = chr((ord($uuidBytes[6]) & 0x0f) | 0x40);
        $uuidBytes[8] = chr((ord($uuidBytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($uuidBytes);
        $uuid = sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );

        $confirmationToken = null;
        $confirmationHashSql = 'NULL';
        $expirySql = 'NULL';
        $status = 'queued';
        if ($operation === 'unlock_account') {
            $confirmationToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $confirmationHashSql = "'" . hash('sha256', $confirmationToken) . "'";
            $expirySql = 'DATE_ADD(NOW(), INTERVAL ' . self::CONFIRMATION_TTL_MINUTES . ' MINUTE)';
            $status = 'pending_confirmation';
        }

        $safeUuid = $DB->escape($uuid);
        $safeSam = $DB->escape($samAccountName);
        $insert = $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_ad_requests
                (request_uuid, operation, sam_account_name, requested_by_users_id,
                 telegram_chat_id, status, confirmation_token_hash, confirmation_expires_at)
             VALUES
                ('{$safeUuid}', '{$operation}', '{$safeSam}', {$usersId},
                 '{$safeChatId}', '{$status}', {$confirmationHashSql}, {$expirySql})"
        );
        if ($insert === false) {
            throw new RuntimeException('No se pudo registrar la solicitud AD.');
        }

        self::audit($uuid, $usersId, 'request_created', [
            'operation' => $operation,
            'sam_account_name' => $samAccountName,
            'status' => $status,
        ]);

        return [
            'request_uuid' => $uuid,
            'status' => $status,
            'confirmation_token' => $confirmationToken,
            'expires_in' => $operation === 'unlock_account' ? self::CONFIRMATION_TTL_MINUTES * 60 : 0,
        ];
    }

    /**
     * Atomically consume a one-time confirmation token. Replays and expired
     * confirmations cannot move a request to the execution queue.
     */
    public static function confirmUnlock(string $requestUuid, string $token, string $chatId): bool
    {
        global $DB;

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $requestUuid)
            || !preg_match('/^[A-Za-z0-9_-]{40,50}$/', $token)
            || !preg_match('/^\d{1,20}$/', $chatId)
        ) {
            return false;
        }

        $uuid = $DB->escape($requestUuid);
        $tokenHash = hash('sha256', $token);
        $safeChatId = $DB->escape($chatId);
        $updated = $DB->doQuery(
            "UPDATE glpi_plugin_telegram_ad_requests
             SET status = 'queued', confirmed_at = NOW(),
                 confirmation_token_hash = NULL, confirmation_expires_at = NULL
             WHERE request_uuid = '{$uuid}'
               AND operation = 'unlock_account'
               AND telegram_chat_id = '{$safeChatId}'
               AND status = 'pending_confirmation'
               AND confirmation_token_hash = '{$tokenHash}'
               AND confirmation_expires_at > NOW()"
        );
        if ($updated === false || $DB->affectedRows() !== 1) {
            return false;
        }

        self::audit($requestUuid, 0, 'unlock_confirmed', [
            'telegram_chat_id' => $chatId,
        ]);

        return true;
    }

    private static function audit(string $requestUuid, int $actorId, string $event, array $details): void
    {
        global $DB;

        $uuid = $DB->escape($requestUuid);
        $actor = $actorId > 0 ? (string) $actorId : 'telegram';
        $safeActor = $DB->escape($actor);
        $safeEvent = $DB->escape($event);
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $safeDetails = $DB->escape($json === false ? '{}' : $json);
        $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_ad_audit
                (request_id, actor_type, actor_id, event, details)
             SELECT id, 'telegram', '{$safeActor}', '{$safeEvent}', '{$safeDetails}'
             FROM glpi_plugin_telegram_ad_requests
             WHERE request_uuid = '{$uuid}'
             LIMIT 1"
        );
    }
}
