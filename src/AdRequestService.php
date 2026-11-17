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
        string $chatId,
        ?int $telegramUpdateId = null
    ): array {
        global $DB;

        if (!in_array($operation, ['account_status', 'unlock_account', 'list_locked_accounts'], true)) {
            throw new RuntimeException('Operación AD no permitida.');
        }
        if ($operation === 'list_locked_accounts' ? $samAccountName !== '*' : !preg_match('/^[A-Za-z0-9._-]{1,20}$/', $samAccountName)) {
            throw new RuntimeException('Nombre de cuenta AD no válido.');
        }
        if ($usersId < 1 || !preg_match('/^\d{1,20}$/', $chatId)
            || ($telegramUpdateId !== null && $telegramUpdateId < 1)
        ) {
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
            (in_array($operation, ['account_status', 'list_locked_accounts'], true) && (int) $rights['can_view'] === 1)
            || ($operation === 'unlock_account' && (int) $rights['can_unlock'] === 1)
        );
        if (!$allowed) {
            throw new RuntimeException('El usuario GLPI no tiene autorización para esta operación AD.');
        }

        $rateLimit = $operation === 'unlock_account' ? 3 : 10;
        $rateWindowMinutes = $operation === 'unlock_account' ? 5 : 1;
        $rateResult = $DB->doQuery(
            "SELECT COUNT(*) AS total
             FROM glpi_plugin_telegram_ad_requests
             WHERE requested_by_users_id = {$usersId}
               AND telegram_chat_id = '{$safeChatId}'
               AND date_creation > DATE_SUB(NOW(), INTERVAL {$rateWindowMinutes} MINUTE)"
        );
        $rateRow = $rateResult ? $DB->fetchAssoc($rateResult) : false;
        if (!$rateRow) {
            throw new RuntimeException('No se pudo validar el límite de solicitudes AD.');
        }
        if ((int) $rateRow['total'] >= $rateLimit) {
            throw new RuntimeException(
                $operation === 'unlock_account'
                    ? 'Límite alcanzado: máximo 3 desbloqueos cada 5 minutos.'
                    : 'Límite alcanzado: máximo 10 consultas por minuto.'
            );
        }

        $uuidBytes = random_bytes(16);
        $uuidBytes[6] = chr((ord($uuidBytes[6]) & 0x0f) | 0x40);
        $uuidBytes[8] = chr((ord($uuidBytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($uuidBytes);
        $uuid = sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );

        if ($operation === 'unlock_account') {
            // Keep only one live confirmation per account and private chat.
            $DB->doQuery(
                "UPDATE glpi_plugin_telegram_ad_requests
                 SET status = 'cancelled', confirmation_token_hash = NULL,
                     confirmation_expires_at = NULL
                 WHERE operation = 'unlock_account'
                   AND sam_account_name = '" . $DB->escape($samAccountName) . "'
                   AND telegram_chat_id = '{$safeChatId}'
                   AND status = 'pending_confirmation'"
            );
        }

        $confirmationToken = null;
        $confirmationHashSql = 'NULL';
        $expirySql = 'NULL';
        $status = 'queued';
        if ($operation === 'unlock_account') {
            $confirmationToken = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            $confirmationHashSql = "'" . hash('sha256', $confirmationToken) . "'";
            $expirySql = 'DATE_ADD(NOW(), INTERVAL ' . self::CONFIRMATION_TTL_MINUTES . ' MINUTE)';
            $status = 'pending_confirmation';
        }

        $targetAgentId = AgentRegistry::getDefaultAgentId();
        if ($targetAgentId === null) {
            throw new RuntimeException('No hay un agente AD aprobado y configurado como predeterminado.');
        }
        $safeTargetAgent = $DB->escape($targetAgentId);
        $safeUuid = $DB->escape($uuid);
        $safeSam = $DB->escape($samAccountName);
        $updateIdSql = $telegramUpdateId === null ? 'NULL' : (string) $telegramUpdateId;
        $insert = $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_ad_requests
                (request_uuid, operation, sam_account_name, requested_by_users_id,
                 telegram_chat_id, telegram_update_id, target_agent_id, status, confirmation_token_hash, confirmation_expires_at)
             VALUES
                ('{$safeUuid}', '{$operation}', '{$safeSam}', {$usersId},
                 '{$safeChatId}', {$updateIdSql}, '{$safeTargetAgent}', '{$status}', {$confirmationHashSql}, {$expirySql})"
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
    public static function confirmUnlock(string $token, string $chatId): bool
    {
        global $DB;

        if (!preg_match('/^[A-Za-z0-9_-]{32}$/', $token)
            || !preg_match('/^\\d{1,20}$/', $chatId)
        ) {
            return false;
        }

        $tokenHash = hash('sha256', $token);
        $safeChatId = $DB->escape($chatId);
        $lookup = $DB->doQuery(
            "SELECT r.request_uuid, r.requested_by_users_id
             FROM glpi_plugin_telegram_ad_requests r
             INNER JOIN glpi_plugin_telegram_ad_authorizations a
               ON a.users_id = r.requested_by_users_id
             INNER JOIN glpi_users u ON u.id = r.requested_by_users_id
             INNER JOIN glpi_plugin_telegram_destinations d
               ON d.users_id = r.requested_by_users_id
              AND d.chat_id = r.telegram_chat_id
              AND d.chat_type = 'private'
              AND d.is_active = 1
             WHERE r.operation = 'unlock_account'
               AND r.telegram_chat_id = '{$safeChatId}'
               AND r.status = 'pending_confirmation'
               AND r.confirmation_token_hash = '{$tokenHash}'
               AND r.confirmation_expires_at > NOW()
               AND a.can_unlock = 1 AND a.is_active = 1
               AND u.is_active = 1 AND u.is_deleted = 0
             LIMIT 1"
        );
        $row = $lookup ? $DB->fetchAssoc($lookup) : false;
        if (!$row || empty($row['request_uuid'])) {
            return false;
        }

        $requestUuid = (string) $row['request_uuid'];
        $safeUuid = $DB->escape($requestUuid);
        $updated = $DB->doQuery(
            "UPDATE glpi_plugin_telegram_ad_requests
             SET status = 'queued', confirmed_at = NOW(),
                 confirmation_token_hash = NULL, confirmation_expires_at = NULL
             WHERE request_uuid = '{$safeUuid}'
               AND operation = 'unlock_account'
               AND telegram_chat_id = '{$safeChatId}'
               AND status = 'pending_confirmation'
               AND confirmation_token_hash = '{$tokenHash}'
               AND confirmation_expires_at > NOW()"
        );
        if ($updated === false || $DB->affectedRows() !== 1) {
            return false;
        }

        self::audit($requestUuid, (int) $row['requested_by_users_id'], 'unlock_confirmed', [
            'telegram_chat_id' => $chatId,
        ]);

        return true;
    }

    public static function cancelUnlock(string $token, string $chatId): bool
    {
        global $DB;

        if (!preg_match('/^[A-Za-z0-9_-]{32}$/', $token)
            || !preg_match('/^\\d{1,20}$/', $chatId)
        ) {
            return false;
        }
        $tokenHash = hash('sha256', $token);
        $safeChatId = $DB->escape($chatId);
        $lookup = $DB->doQuery(
            "SELECT request_uuid, requested_by_users_id
             FROM glpi_plugin_telegram_ad_requests
             WHERE operation = 'unlock_account'
               AND telegram_chat_id = '{$safeChatId}'
               AND status = 'pending_confirmation'
               AND confirmation_token_hash = '{$tokenHash}'
               AND confirmation_expires_at > NOW()
             LIMIT 1"
        );
        $row = $lookup ? $DB->fetchAssoc($lookup) : false;
        if (!$row || empty($row['request_uuid'])) {
            return false;
        }
        $uuid = $DB->escape((string) $row['request_uuid']);
        $updated = $DB->doQuery(
            "UPDATE glpi_plugin_telegram_ad_requests
             SET status = 'cancelled', confirmation_token_hash = NULL,
                 confirmation_expires_at = NULL
             WHERE request_uuid = '{$uuid}'
               AND telegram_chat_id = '{$safeChatId}'
               AND status = 'pending_confirmation'
               AND confirmation_token_hash = '{$tokenHash}'
               AND confirmation_expires_at > NOW()"
        );
        if ($updated === false || $DB->affectedRows() !== 1) {
            return false;
        }
        self::audit((string) $row['request_uuid'], (int) $row['requested_by_users_id'], 'unlock_cancelled', [
            'telegram_chat_id' => $chatId,
        ]);
        return true;
    }

    /**
     * Atomically lease the oldest queued request to one authenticated agent.
     * A lost response is recovered after the 90-second lease expires.
     */
    public static function claimNext(string $agentId): ?array
    {
        global $DB;

        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId)) {
            throw new RuntimeException('Identificador de agente no válido.');
        }
        $safeAgent = $DB->escape($agentId);

        $recovered = $DB->doQuery(
            "UPDATE glpi_plugin_telegram_ad_requests
             SET status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'queued' END,
                 result_code = CASE WHEN attempts >= 3 THEN 'agent_timeout' ELSE NULL END,
                 result_message = CASE WHEN attempts >= 3 THEN 'El agente no completó la solicitud tras tres leases.' ELSE NULL END,
                 date_completed = CASE WHEN attempts >= 3 THEN NOW() ELSE NULL END,
                 agent_id = NULL, lease_token_hash = NULL, lease_expires_at = NULL
             WHERE status = 'claimed' AND lease_expires_at < NOW()"
        );
        if ($recovered === false) {
            throw new RuntimeException('No se pudieron recuperar leases vencidos.');
        }

        $active = $DB->doQuery(
            "SELECT id FROM glpi_plugin_telegram_ad_requests
             WHERE status = 'claimed' AND agent_id = '{$safeAgent}'
               AND lease_expires_at > NOW() LIMIT 1"
        );
        if ($active && $DB->fetchAssoc($active)) {
            return null;
        }

        $leaseToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $leaseHash = hash('sha256', $leaseToken);
        $claimed = $DB->doQuery(
            "UPDATE glpi_plugin_telegram_ad_requests
             SET status = 'claimed', agent_id = '{$safeAgent}',
                 lease_token_hash = '{$leaseHash}',
                 lease_expires_at = DATE_ADD(NOW(), INTERVAL 90 SECOND),
                 attempts = attempts + 1
             WHERE id = (
                 SELECT id FROM (
                     SELECT id FROM glpi_plugin_telegram_ad_requests
                     WHERE status = 'queued' AND target_agent_id = '{$safeAgent}'
                     ORDER BY date_creation ASC, id ASC LIMIT 1
                 ) AS next_request
             ) AND status = 'queued'"
        );
        if ($claimed === false) {
            throw new RuntimeException('No se pudo reclamar la solicitud AD.');
        }
        if ($DB->affectedRows() !== 1) {
            return null;
        }

        $lookup = $DB->doQuery(
            "SELECT request_uuid, operation, sam_account_name, lease_expires_at, attempts
             FROM glpi_plugin_telegram_ad_requests
             WHERE status = 'claimed' AND agent_id = '{$safeAgent}'
               AND lease_token_hash = '{$leaseHash}' LIMIT 1"
        );
        $row = $lookup ? $DB->fetchAssoc($lookup) : false;
        if (!$row) {
            throw new RuntimeException('No se pudo recuperar la solicitud reclamada.');
        }

        $uuid = (string) $row['request_uuid'];
        self::audit($uuid, $agentId, 'request_claimed', [
            'operation' => (string) $row['operation'],
            'attempt' => (int) ($row['attempts'] ?? 1),
        ], 'agent');

        return [
            'request_uuid' => $uuid,
            'operation' => (string) $row['operation'],
            'sam_account_name' => (string) $row['sam_account_name'],
            'lease_token' => $leaseToken,
            'lease_expires_at' => (string) $row['lease_expires_at'],
        ];
    }

    /**
     * Complete only the request currently leased to this agent.
     * Result fields are allowlisted; free-form PowerShell output is never stored.
     */
    public static function complete(
        string $agentId,
        string $requestUuid,
        string $leaseToken,
        string $resultCode,
        array $data = []
    ): ?array {
        global $DB;

        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId)
            || !preg_match('/^[0-9a-f-]{36}$/i', $requestUuid)
            || !preg_match('/^[A-Za-z0-9_-]{43}$/', $leaseToken)
        ) {
            return null;
        }

        $safeAgent = $DB->escape($agentId);
        $safeUuid = $DB->escape($requestUuid);
        $leaseHash = hash('sha256', $leaseToken);
        $lookup = $DB->doQuery(
            "SELECT id, operation, sam_account_name, telegram_chat_id, requested_by_users_id
             FROM glpi_plugin_telegram_ad_requests
             WHERE request_uuid = '{$safeUuid}'
               AND status = 'claimed'
               AND agent_id = '{$safeAgent}'
               AND lease_token_hash = '{$leaseHash}'
               AND lease_expires_at > NOW()
             LIMIT 1"
        );
        $row = $lookup ? $DB->fetchAssoc($lookup) : false;
        if (!$row) {
            return null;
        }

        $operation = (string) $row['operation'];
        $sam = (string) $row['sam_account_name'];
        $successful = false;
        $resultData = [];
        $auditDetails = [];
        if ($operation === 'list_locked_accounts') {
            if (!in_array($resultCode, ['ok', 'access_denied', 'error'], true)) {
                return null;
            }
            if ($resultCode === 'ok') {
                if (array_keys($data) !== ['accounts'] || !is_array($data['accounts']) || count($data['accounts']) > 200) {
                    return null;
                }
                $accounts = [];
                foreach ($data['accounts'] as $account) {
                    if (!is_array($account)
                        || !isset($account['sam_account_name'], $account['display_name'])
                        || !is_string($account['sam_account_name'])
                        || !preg_match('/^[A-Za-z0-9._-]{1,20}$/', $account['sam_account_name'])
                        || !is_string($account['display_name'])
                        || mb_strlen($account['display_name'], 'UTF-8') > 120
                    ) {
                        return null;
                    }
                    $accounts[] = [
                        'sam_account_name' => $account['sam_account_name'],
                        'display_name' => $account['display_name'],
                    ];
                }
                $resultData = ['accounts' => $accounts];
                $auditDetails = ['accounts' => $accounts];
                $message = count($accounts) === 0
                    ? 'No hay cuentas habilitadas y bloqueadas en Active Directory.'
                    : 'Se encontraron ' . count($accounts) . ' cuentas habilitadas y bloqueadas.';
                $successful = true;
            } else {
                if ($data !== []) { return null; }
                $message = $resultCode === 'access_denied'
                    ? 'El agente no tiene permisos para consultar las cuentas bloqueadas.'
                    : 'No se pudo consultar el listado de cuentas bloqueadas.';
            }
        } elseif ($operation === 'account_status') {
            if ($resultCode !== 'ok' && !in_array($resultCode, ['not_found', 'access_denied', 'error'], true)) {
                return null;
            }
            if ($resultCode === 'ok') {
                if (!isset($data['enabled'], $data['locked_out'])
                    || !is_bool($data['enabled']) || !is_bool($data['locked_out'])
                ) {
                    return null;
                }
                $enabled = $data['enabled'] ? 'habilitada' : 'deshabilitada';
                $locked = $data['locked_out'] ? 'bloqueada' : 'no bloqueada';
                $message = "Cuenta {$sam}: {$enabled}; {$locked}.";
                $successful = true;
            } else {
                $message = match ($resultCode) {
                    'not_found' => "La cuenta {$sam} no existe en Active Directory.",
                    'access_denied' => "El agente no tiene permisos para consultar {$sam}.",
                    default => "No se pudo consultar {$sam} por un error del agente.",
                };
            }
        } elseif ($operation === 'unlock_account') {
            if (!in_array($resultCode, ['unlocked', 'already_unlocked', 'account_disabled', 'not_found', 'access_denied', 'error'], true)
                || $data !== []
            ) {
                return null;
            }
            $successful = in_array($resultCode, ['unlocked', 'already_unlocked'], true);
            $message = match ($resultCode) {
                'unlocked' => "La cuenta {$sam} fue desbloqueada correctamente.",
                'already_unlocked' => "La cuenta {$sam} ya estaba desbloqueada.",
                'account_disabled' => "La cuenta {$sam} está deshabilitada. No se realizó ningún cambio.",
                'not_found' => "La cuenta {$sam} no existe en Active Directory.",
                'access_denied' => "El agente no tiene permisos para desbloquear {$sam}.",
                default => "No se pudo desbloquear {$sam} por un error del agente.",
            };
        } else {
            return null;
        }

        $status = $successful ? 'completed' : 'failed';
        $safeCode = $DB->escape($resultCode);
        $safeMessage = $DB->escape($message);
        $requestId = (int) $row['id'];
        $updated = $DB->doQuery(
            "UPDATE glpi_plugin_telegram_ad_requests
             SET status = '{$status}', result_code = '{$safeCode}',
                 result_message = '{$safeMessage}', date_completed = NOW(),
                 lease_token_hash = NULL, lease_expires_at = NULL
             WHERE id = {$requestId} AND status = 'claimed'
               AND agent_id = '{$safeAgent}' AND lease_token_hash = '{$leaseHash}'
               AND lease_expires_at > NOW()"
        );
        if ($updated === false || $DB->affectedRows() !== 1) {
            return null;
        }

        self::audit($requestUuid, $agentId, 'request_' . $status, array_merge([
            'operation' => $operation,
            'result_code' => $resultCode,
        ], $auditDetails), 'agent');

        return [
            'request_uuid' => $requestUuid,
            'operation' => $operation,
            'sam_account_name' => $sam,
            'telegram_chat_id' => (string) $row['telegram_chat_id'],
            'requested_by_users_id' => (int) $row['requested_by_users_id'],
            'result_code' => $resultCode,
            'result_message' => $message,
            'status' => $status,
            'result_data' => $resultData,
        ];
    }

    /** Return one page from a completed locked-account snapshot, scoped to its requester/chat. */
    public static function getLockedAccountsPage(string $requestUuid, string $chatId, int $usersId, int $page): array
    {
        global $DB;
        if (!preg_match('/^[0-9a-f-]{36}$/i', $requestUuid)
            || !preg_match('/^\\d{1,20}$/', $chatId)
            || $usersId < 1 || $page < 0 || $page > 49
        ) {
            throw new RuntimeException('La página solicitada no es válida.');
        }
        $uuid = $DB->escape($requestUuid);
        $safeChat = $DB->escape($chatId);
        $lookup = $DB->doQuery(
            "SELECT a.details
             FROM glpi_plugin_telegram_ad_requests r
             INNER JOIN glpi_plugin_telegram_ad_audit a ON a.request_id = r.id
             INNER JOIN glpi_plugin_telegram_ad_authorizations p ON p.users_id = r.requested_by_users_id
             INNER JOIN glpi_users u ON u.id = r.requested_by_users_id
             WHERE r.request_uuid = '{$uuid}' AND r.operation = 'list_locked_accounts'
               AND r.status = 'completed' AND r.requested_by_users_id = {$usersId}
               AND r.telegram_chat_id = '{$safeChat}'
               AND a.event = 'request_completed' AND p.can_view = 1 AND p.is_active = 1
               AND u.is_active = 1 AND u.is_deleted = 0
             ORDER BY a.id DESC LIMIT 1"
        );
        $row = $lookup ? $DB->fetchAssoc($lookup) : false;
        if (!$row || !is_string($row['details'] ?? null)) {
            throw new RuntimeException('El listado ya no está disponible o no tienes autorización.');
        }
        $details = json_decode($row['details'], true);
        $accounts = $details['accounts'] ?? null;
        if (!is_array($accounts)) {
            throw new RuntimeException('No se pudo recuperar el listado de Active Directory.');
        }
        $total = count($accounts);
        $pages = max(1, (int) ceil($total / 10));
        if ($page >= $pages) {
            throw new RuntimeException('La página solicitada ya no existe.');
        }
        return [
            'accounts' => array_slice($accounts, $page * 10, 10),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** Confirm that a Telegram account-selection callback refers to an account in this user's snapshot. */
    public static function isAccountInLockedSnapshot(string $requestUuid, string $chatId, int $usersId, string $samAccountName): bool
    {
        global $DB;
        if (!preg_match('/^[0-9a-f-]{36}$/i', $requestUuid)
            || !preg_match('/^\\d{1,20}$/', $chatId)
            || !preg_match('/^[A-Za-z0-9._-]{1,20}$/', $samAccountName)
            || $usersId < 1
        ) {
            return false;
        }
        $uuid = $DB->escape($requestUuid);
        $safeChat = $DB->escape($chatId);
        $lookup = $DB->doQuery(
            "SELECT a.details
             FROM glpi_plugin_telegram_ad_requests r
             INNER JOIN glpi_plugin_telegram_ad_audit a ON a.request_id = r.id
             INNER JOIN glpi_plugin_telegram_ad_authorizations p ON p.users_id = r.requested_by_users_id
             INNER JOIN glpi_users u ON u.id = r.requested_by_users_id
             WHERE r.request_uuid = '{$uuid}' AND r.operation = 'list_locked_accounts'
               AND r.status = 'completed' AND r.requested_by_users_id = {$usersId}
               AND r.telegram_chat_id = '{$safeChat}'
               AND a.event = 'request_completed' AND p.can_view = 1 AND p.is_active = 1
               AND u.is_active = 1 AND u.is_deleted = 0
             ORDER BY a.id DESC LIMIT 1"
        );
        $row = $lookup ? $DB->fetchAssoc($lookup) : false;
        if (!$row || !is_string($row['details'] ?? null)) {
            return false;
        }
        $details = json_decode($row['details'], true);
        foreach (($details['accounts'] ?? []) as $account) {
            if (is_array($account) && ($account['sam_account_name'] ?? null) === $samAccountName) {
                return true;
            }
        }
        return false;
    }

    private static function audit(string $requestUuid, string|int $actorId, string $event, array $details, string $actorType = 'telegram'): void
    {
        global $DB;

        $uuid = $DB->escape($requestUuid);
        $actor = (string) $actorId;
        $safeActor = $DB->escape($actor);
        $safeActorType = $DB->escape($actorType);
        $safeEvent = $DB->escape($event);
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $safeDetails = $DB->escape($json === false ? '{}' : $json);
        $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_ad_audit
                (request_id, actor_type, actor_id, event, details)
             SELECT id, '{$safeActorType}', '{$safeActor}', '{$safeEvent}', '{$safeDetails}'
             FROM glpi_plugin_telegram_ad_requests
             WHERE request_uuid = '{$uuid}'
             LIMIT 1"
        );
    }
}
