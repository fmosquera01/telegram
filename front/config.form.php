<?php

use GlpiPlugin\Telegram\SecretStore;
use GlpiPlugin\Telegram\TelegramClient;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);
Session::checkCSRF($_POST);

global $DB;

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'test_bot') {
        $bot = (new TelegramClient())->getMe();
        $username = (string) ($bot['username'] ?? 'sin nombre');
        Session::addMessageAfterRedirect('Conexión correcta con el bot @' . $username . '.', false, INFO);
    } elseif ($action === 'save_token') {
        $token = trim((string) ($_POST['bot_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Ingrese el token del bot.');
        }
        SecretStore::saveBotToken($token);
        Session::addMessageAfterRedirect('Token Telegram guardado y cifrado correctamente.', false, INFO);
    } elseif ($action === 'add_destination') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $alias = trim((string) ($_POST['alias'] ?? ''));
        $chatId = trim((string) ($_POST['chat_id'] ?? ''));
        $userId = (int) ($_POST['users_id'] ?? 0);
        $chatType = (string) ($_POST['chat_type'] ?? 'private');

        if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{2,120}$/', $alias)
            || $chatId === '' || strlen($chatId) > 64
            || !in_array($chatType, ['private', 'group', 'supergroup', 'channel'], true)
        ) {
            throw new RuntimeException('Revise el nombre, alias, Chat ID y tipo de destino.');
        }

        if ($userId > 0) {
            $userCheck = $DB->doQuery(
                "SELECT id FROM glpi_users
                 WHERE id = {$userId} AND is_active = 1 AND is_deleted = 0 LIMIT 1"
            );
            if (!$userCheck || $DB->numrows($userCheck) !== 1) {
                throw new RuntimeException('El usuario GLPI seleccionado no existe o está inactivo.');
            }
        }
        $userIdSql = $userId > 0 ? (string) $userId : 'NULL';

        $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_destinations
                (name, alias, chat_id, users_id, chat_type)
             VALUES ('" . $DB->escape($name) . "', '" . $DB->escape($alias) . "',
                     '" . $DB->escape($chatId) . "', {$userIdSql}, '" . $DB->escape($chatType) . "')"
        );
        Session::addMessageAfterRedirect('Destino Telegram agregado.', false, INFO);
    } elseif ($action === 'add_subscription') {
        $source = trim((string) ($_POST['source'] ?? ''));
        $event = trim((string) ($_POST['event'] ?? ''));
        $destinationId = (int) ($_POST['destination_id'] ?? 0);

        if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $source)
            || !preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $event)
            || $destinationId < 1
        ) {
            throw new RuntimeException('Revise el origen, evento y destino seleccionado.');
        }

        $destinationCheck = $DB->doQuery(
            "SELECT id FROM glpi_plugin_telegram_destinations
             WHERE id = {$destinationId} AND is_active = 1 LIMIT 1"
        );
        if (!$destinationCheck || $DB->numrows($destinationCheck) !== 1) {
            throw new RuntimeException('El destino seleccionado no existe o está inactivo.');
        }

        $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_subscriptions (`source`, `event`, destination_id)
             VALUES ('" . $DB->escape($source) . "', '" . $DB->escape($event) . "', {$destinationId})
             ON DUPLICATE KEY UPDATE is_active = 1"
        );
        Session::addMessageAfterRedirect('Suscripción de evento guardada.', false, INFO);
    } elseif ($action === 'toggle_destination') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Destino no válido.');
        }
        $DB->doQuery("UPDATE glpi_plugin_telegram_destinations SET is_active = IF(is_active = 1, 0, 1) WHERE id = {$id}");
        Session::addMessageAfterRedirect('Estado del destino actualizado.', false, INFO);
    } elseif ($action === 'toggle_subscription') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Suscripción no válida.');
        }
        $DB->doQuery("UPDATE glpi_plugin_telegram_subscriptions SET is_active = IF(is_active = 1, 0, 1) WHERE id = {$id}");
        Session::addMessageAfterRedirect('Estado de la suscripción actualizado.', false, INFO);
    } elseif ($action === 'retry_queue') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Registro de cola no válido.');
        }
        $DB->doQuery(
            "UPDATE glpi_plugin_telegram_queue
             SET status = 'retry', attempts = 0, next_attempt_at = NOW(),
                 processing_at = NULL, last_error = NULL
             WHERE id = {$id} AND status IN ('failed', 'retry')"
        );
        Session::addMessageAfterRedirect('Notificación programada nuevamente.', false, INFO);
    } else {
        throw new RuntimeException('Acción no reconocida.');
    }
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect('FML Telegram: ' . $exception->getMessage(), false, ERROR);
}

Html::redirect($CFG_GLPI['root_doc'] . '/plugins/telegram/front/config.php');
