<?php

use GlpiPlugin\Telegram\SecretStore;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);
Session::checkCSRF($_POST);

global $DB;

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'save_token') {
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
        $chatType = (string) ($_POST['chat_type'] ?? 'private');

        if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{2,120}$/', $alias)
            || $chatId === '' || strlen($chatId) > 64
            || !in_array($chatType, ['private', 'group', 'supergroup', 'channel'], true)
        ) {
            throw new RuntimeException('Revise el nombre, alias, Chat ID y tipo de destino.');
        }

        $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_destinations (name, alias, chat_id, chat_type)
             VALUES ('" . $DB->escape($name) . "', '" . $DB->escape($alias) . "',
                     '" . $DB->escape($chatId) . "', '" . $DB->escape($chatType) . "')"
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

        $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_subscriptions (source, event, destination_id)
             VALUES ('" . $DB->escape($source) . "', '" . $DB->escape($event) . "', {$destinationId})
             ON DUPLICATE KEY UPDATE is_active = 1"
        );
        Session::addMessageAfterRedirect('Suscripción de evento guardada.', false, INFO);
    } else {
        throw new RuntimeException('Acción no reconocida.');
    }
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect('FML Telegram: ' . $exception->getMessage(), false, ERROR);
}

Html::redirect($CFG_GLPI['root_doc'] . '/plugins/telegram/front/config.php');
