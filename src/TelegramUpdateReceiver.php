<?php

namespace GlpiPlugin\Telegram;

use RuntimeException;
use Throwable;

final class TelegramUpdateReceiver
{
    public static function run(): void
    {
        if (!defined('GLPI_VAR_DIR')) {
            throw new RuntimeException('GLPI_VAR_DIR is not available.');
        }
        $directory = GLPI_VAR_DIR . '/_plugins/telegram';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the Telegram receiver state directory.');
        }
        $lock = fopen($directory . '/receiver.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another FML Telegram receiver process is already running.');
        }

        $client = new TelegramClient();
        $webhook = $client->getWebhookInfo();
        if (!empty($webhook['url'])) {
            throw new RuntimeException('Telegram webhook is configured. Disable it before starting the Long Polling receiver.');
        }

        $offset = self::getOffset();
        while (true) {
            try {
                $updates = $client->getUpdates($offset, 25);
                foreach ($updates as $update) {
                    if (!is_array($update) || !isset($update['update_id'])) {
                        continue;
                    }
                    self::processUpdate($client, $update);
                    $offset = max($offset, (int) $update['update_id'] + 1);
                    self::saveOffset($offset);
                }
            } catch (Throwable $exception) {
                error_log('[FML Telegram receiver] ' . self::safeError($exception->getMessage()));
                sleep(3);
            }
        }
    }

    private static function processUpdate(TelegramClient $client, array $update): void
    {
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            self::processCallback($client, $update['callback_query']);
            return;
        }
        if (isset($update['message']) && is_array($update['message'])) {
            self::processMessage($client, $update['message'], (int) $update['update_id']);
        }
    }

    private static function processMessage(TelegramClient $client, array $message, int $updateId): void
    {
        $chat = $message['chat'] ?? [];
        $sender = $message['from'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $senderId = (string) ($sender['id'] ?? '');
        if (($chat['type'] ?? '') !== 'private' || $chatId === '' || $chatId !== $senderId
            || !preg_match('/^\\d{1,20}$/', $chatId) || !empty($sender['is_bot'])
        ) {
            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            return;
        }
        if (preg_match('/^\\/(?:start|ayuda)(?:@[A-Za-z0-9_]+)?$/i', $text)) {
            $client->sendMessage($chatId, "Comandos disponibles:\n/bloqueados — lista cuentas habilitadas y bloqueadas\n/estado usuario\n/desbloquear usuario");
            return;
        }
        if (preg_match('/^\\/bloqueados(?:@[A-Za-z0-9_]+)?$/i', $text)) {
            $usersId = self::resolveUserId($chatId);
            if ($usersId === null) {
                $client->sendMessage($chatId, 'Este chat privado no está vinculado a un usuario GLPI activo. Contacta al administrador.');
                return;
            }
            try {
                $request = AdRequestService::createRequest('list_locked_accounts', '*', $usersId, $chatId, $updateId);
                $client->sendMessage($chatId, 'Consultando Active Directory. Te enviaré el listado cuando responda el agente.');
            } catch (Throwable $exception) {
                $client->sendMessage($chatId, self::safeError($exception->getMessage()));
            }
            return;
        }

        if (!preg_match('/^\\/(estado|desbloquear)(?:@[A-Za-z0-9_]+)?\\s+([A-Za-z0-9._-]{1,20})$/i', $text, $matches)) {
            if (preg_match('/^\\/(estado|desbloquear)(?:@[A-Za-z0-9_]+)?/i', $text)) {
                $client->sendMessage($chatId, "Formato incorrecto. Usa /estado usuario o /desbloquear usuario.");
            }
            return;
        }

        $usersId = self::resolveUserId($chatId);
        if ($usersId === null) {
            $client->sendMessage($chatId, 'Este chat privado no está vinculado a un usuario GLPI activo. Contacta al administrador.');
            return;
        }

        $operation = strtolower($matches[1]) === 'estado' ? 'account_status' : 'unlock_account';
        try {
            $request = AdRequestService::createRequest($operation, $matches[2], $usersId, $chatId, $updateId);
            if ($operation === 'account_status') {
                $client->sendMessage($chatId, 'Solicitud de consulta registrada. Está en cola y pendiente de respuesta del agente AD.');
                return;
            }

            $token = (string) $request['confirmation_token'];
            $markup = [
                'inline_keyboard' => [[
                    ['text' => 'Confirmar desbloqueo', 'callback_data' => 'adok:' . $token],
                    ['text' => 'Cancelar', 'callback_data' => 'adno:' . $token],
                ]],
            ];
            $client->sendMessage(
                $chatId,
                'Confirma el desbloqueo de la cuenta ' . $matches[2] . '. Esta confirmación vence en 5 minutos.',
                null,
                $markup
            );
        } catch (Throwable $exception) {
            $client->sendMessage($chatId, self::safeError($exception->getMessage()));
        }
    }

    private static function processCallback(TelegramClient $client, array $callback): void
    {
        $message = $callback['message'] ?? [];
        $chat = $message['chat'] ?? [];
        $sender = $callback['from'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $senderId = (string) ($sender['id'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');

        if ($callbackId === '') {
            return;
        }
        if (($chat['type'] ?? '') !== 'private' || $chatId === '' || $chatId !== $senderId
            || !preg_match('/^\\d{1,20}$/', $chatId)
        ) {
            $client->answerCallbackQuery($callbackId, 'Acción no autorizada.');
            return;
        }

        if (preg_match('/^adok:([A-Za-z0-9_-]{32})$/', $data, $matches)) {
            $success = AdRequestService::confirmUnlock($matches[1], $chatId);
            $client->answerCallbackQuery($callbackId, $success ? 'Desbloqueo confirmado.' : 'La confirmación venció o ya fue utilizada.');
            if ($success) {
                $client->sendMessage($chatId, 'Solicitud confirmada y enviada a la cola del agente AD.');
            }
            return;
        }
        if (preg_match('/^adno:([A-Za-z0-9_-]{32})$/', $data, $matches)) {
            $success = AdRequestService::cancelUnlock($matches[1], $chatId);
            $client->answerCallbackQuery($callbackId, $success ? 'Solicitud cancelada.' : 'La solicitud venció o ya fue procesada.');
            if ($success) {
                $client->sendMessage($chatId, 'Solicitud de desbloqueo cancelada.');
            }
            return;
        }
        if (preg_match('/^adpg:([0-9a-f-]{36}):(\\d{1,2})$/i', $data, $matches)) {
            $usersId = self::resolveUserId($chatId);
            if ($usersId === null) {
                $client->answerCallbackQuery($callbackId, 'Chat no vinculado.');
                return;
            }
            try {
                $client->answerCallbackQuery($callbackId, 'Cargando página...');
                self::sendLockedAccountsPage($client, $matches[1], $chatId, $usersId, (int) $matches[2]);
            } catch (Throwable $exception) {
                $client->sendMessage($chatId, self::safeError($exception->getMessage()));
            }
            return;
        }
        if (preg_match('/^adsel:([0-9a-f-]{36}):([A-Za-z0-9._-]{1,20})$/i', $data, $matches)) {
            $usersId = self::resolveUserId($chatId);
            if ($usersId === null) {
                $client->answerCallbackQuery($callbackId, 'Chat no vinculado.');
                return;
            }
            try {
                if (!AdRequestService::isAccountInLockedSnapshot($matches[1], $chatId, $usersId, $matches[2])) {
                    throw new RuntimeException('La cuenta no pertenece al listado autorizado o este ya no está disponible.');
                }
                $request = AdRequestService::createRequest('unlock_account', $matches[2], $usersId, $chatId);
                $token = (string) $request['confirmation_token'];
                $markup = ['inline_keyboard' => [[
                    ['text' => 'Confirmar desbloqueo', 'callback_data' => 'adok:' . $token],
                    ['text' => 'Cancelar', 'callback_data' => 'adno:' . $token],
                ]]];
                $client->answerCallbackQuery($callbackId, 'Confirma la cuenta seleccionada.');
                $client->sendMessage($chatId, 'Confirma el desbloqueo de ' . $matches[2] . '. Esta confirmación vence en 5 minutos.', null, $markup);
            } catch (Throwable $exception) {
                $client->answerCallbackQuery($callbackId, 'No se pudo crear la solicitud.');
                $client->sendMessage($chatId, self::safeError($exception->getMessage()));
            }
            return;
        }
        $client->answerCallbackQuery($callbackId, 'Acción no reconocida.');
    }

    /** Send one 10-account page with per-account unlock and page navigation buttons. */
    public static function sendLockedAccountsPage(TelegramClient $client, string $requestUuid, string $chatId, int $usersId, int $page): void
    {
        $result = AdRequestService::getLockedAccountsPage($requestUuid, $chatId, $usersId, $page);
        if ($result['total'] === 0) {
            $client->sendMessage($chatId, 'No hay cuentas habilitadas y bloqueadas en Active Directory.');
            return;
        }
        $rows = [];
        foreach ($result['accounts'] as $account) {
            $sam = (string) $account['sam_account_name'];
            $name = trim((string) $account['display_name']);
            $label = $name === '' || strcasecmp($name, $sam) === 0 ? $sam : $name . ' (' . $sam . ')';
            $rows[] = [['text' => mb_substr($label, 0, 60, 'UTF-8'), 'callback_data' => 'adsel:' . $requestUuid . ':' . $sam]];
        }
        $navigation = [];
        if ($page > 0) {
            $navigation[] = ['text' => '◀ Anterior', 'callback_data' => 'adpg:' . $requestUuid . ':' . ($page - 1)];
        }
        if ($page + 1 < $result['pages']) {
            $navigation[] = ['text' => 'Siguiente ▶', 'callback_data' => 'adpg:' . $requestUuid . ':' . ($page + 1)];
        }
        if ($navigation !== []) { $rows[] = $navigation; }
        $text = 'Cuentas habilitadas y bloqueadas: ' . $result['total']
            . "\nPágina " . ($page + 1) . ' de ' . $result['pages']
            . "\nSelecciona una cuenta para solicitar su desbloqueo:";
        $client->sendMessage($chatId, $text, null, ['inline_keyboard' => $rows]);
    }

    private static function resolveUserId(string $chatId): ?int
    {
        global $DB;
        $safeChatId = $DB->escape($chatId);
        $result = $DB->doQuery(
            "SELECT d.users_id
             FROM glpi_plugin_telegram_destinations d
             INNER JOIN glpi_users u ON u.id = d.users_id
             WHERE d.chat_id = '{$safeChatId}'
               AND d.chat_type = 'private'
               AND d.is_active = 1
               AND d.users_id IS NOT NULL
               AND u.is_active = 1
               AND u.is_deleted = 0
             LIMIT 1"
        );
        $row = $result ? $DB->fetchAssoc($result) : false;
        return $row ? (int) $row['users_id'] : null;
    }

    private static function getOffset(): int
    {
        global $DB;
        $result = $DB->doQuery(
            "SELECT config_value FROM glpi_plugin_telegram_configs
             WHERE config_key = 'telegram_update_offset' LIMIT 1"
        );
        $row = $result ? $DB->fetchAssoc($result) : false;
        return max(0, (int) ($row['config_value'] ?? 0));
    }

    private static function saveOffset(int $offset): void
    {
        global $DB;
        $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_configs (config_key, config_value)
             VALUES ('telegram_update_offset', '" . $offset . "')
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
    }

    private static function safeError(string $message): string
    {
        $message = preg_replace('/bot\\d+:[A-Za-z0-9_-]+/', '[TOKEN]', $message);
        return mb_substr(trim(strip_tags((string) $message)), 0, 300, 'UTF-8');
    }
}
