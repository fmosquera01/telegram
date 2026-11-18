<?php

use GlpiPlugin\Telegram\SecretStore;
use GlpiPlugin\Telegram\AgentRegistry;
use GlpiPlugin\Telegram\TelegramClient;
use GlpiPlugin\Telegram\NotificationService;
use GlpiPlugin\Telegram\ReceiverManager;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);
// GLPI 11 validates the POST CSRF token in CheckCsrfListener before
// invoking this legacy controller. Do not validate it again here: the
// listener consumes the token, so a second check would reject a valid POST.

global $DB, $CFG_GLPI;

$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'receiver_start') {
        ReceiverManager::start();
        Session::addMessageAfterRedirect('Receptor Telegram iniciado correctamente.', false, INFO);
    } elseif ($action === 'receiver_stop') {
        ReceiverManager::stop();
        Session::addMessageAfterRedirect('Receptor Telegram detenido.', false, INFO);
    } elseif ($action === 'receiver_status') {
        $status = ReceiverManager::status();
        $label = ['running' => 'En ejecución', 'stopped' => 'Detenido', 'unsupported' => 'Control disponible en Laragon; Ubuntu utilizará systemd.'][$status] ?? 'Desconocido';
        Session::addMessageAfterRedirect('Estado del receptor: ' . $label . '.', false, INFO);
    } elseif ($action === 'test_bot') {
        $bot = (new TelegramClient())->getMe();
        $username = (string) ($bot['username'] ?? 'sin nombre');
        Session::addMessageAfterRedirect('Conexión correcta con el bot @' . $username . '.', false, INFO);
    } elseif ($action === 'test_destination') {
        if (SecretStore::getBotToken() === null) {
            throw new RuntimeException('Configure el token del bot antes de probar destinos.');
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Destino no válido.');
        }
        $destinationResult = $DB->doQuery(
            "SELECT name, alias FROM glpi_plugin_telegram_destinations
             WHERE id = {$id} AND is_active = 1 LIMIT 1"
        );
        $destination = $destinationResult ? $DB->fetchAssoc($destinationResult) : false;
        if (!$destination) {
            throw new RuntimeException('El destino no existe o está inactivo.');
        }

        $result = NotificationService::enqueue([
            'source' => 'telegram',
            'event' => 'destination.test',
            'subject' => 'Prueba de destino Telegram',
            'message' => 'Mensaje de prueba para el destino: ' . $destination['name'],
            'recipients' => [(string) $destination['alias']],
        ]);
        if (($result['queued'] ?? 0) < 1) {
            throw new RuntimeException(implode(' ', $result['errors'] ?? ['No se pudo encolar la prueba.']));
        }
        Session::addMessageAfterRedirect('Mensaje de prueba encolado. Se enviará cuando se ejecute la tarea cron.', false, INFO);
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

        if (($userId < 1 && ($name === '' || strlen($name) > 120 || !preg_match('/^[A-Za-z0-9_-]{2,120}$/', $alias)))
            || strlen($chatId) > 64
            || (!preg_match('/^-?\\d{1,20}$/', $chatId) && !preg_match('/^@[A-Za-z0-9_]{5,32}$/', $chatId))
            || !in_array($chatType, ['private', 'group', 'supergroup', 'channel'], true)
        ) {
            throw new RuntimeException('Revise el nombre, alias, Chat ID y tipo de destino.');
        }

        if ($userId > 0) {
            $userCheck = $DB->doQuery(
                "SELECT id, name, firstname, realname FROM glpi_users
                 WHERE id = {$userId} AND is_active = 1 AND is_deleted = 0 LIMIT 1"
            );
            $glpiUser = $userCheck ? $DB->fetchAssoc($userCheck) : false;
            if (!$glpiUser) {
                throw new RuntimeException('El usuario GLPI seleccionado no existe o está inactivo.');
            }
            $login = trim((string) $glpiUser['name']);
            $name = trim((string) $glpiUser['firstname'] . ' ' . (string) $glpiUser['realname']);
            if ($name === '') {
                $name = $login;
            }
            $alias = strtolower($login);
            $alias = trim((string) preg_replace('/[^a-z0-9_-]+/', '_', $alias), '_-');
            if ($alias === '' || !preg_match('/^[A-Za-z0-9_-]{2,120}$/', $alias)) {
                throw new RuntimeException('El usuario GLPI no tiene un nombre de acceso válido para generar el alias. Registre el destino manualmente.');
            }
        }
        if ($name === '' || strlen($name) > 120 || !preg_match('/^[A-Za-z0-9_-]{2,120}$/', $alias)) {
            throw new RuntimeException('No se pudo generar un nombre o alias válido para el destino.');
        }
        $userIdSql = $userId > 0 ? (string) $userId : 'NULL';

        if ($DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_destinations
                (name, alias, chat_id, users_id, chat_type)
             VALUES ('" . $DB->escape($name) . "', '" . $DB->escape($alias) . "',
                     '" . $DB->escape($chatId) . "', {$userIdSql}, '" . $DB->escape($chatType) . "')"
        ) === false) {
            throw new RuntimeException('No se pudo guardar el destino. Verifique que el alias no esté repetido.');
        }
        Session::addMessageAfterRedirect('Destino Telegram agregado.', false, INFO);
    } elseif ($action === 'update_destination') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $chatId = trim((string) ($_POST['chat_id'] ?? ''));
        $userId = (int) ($_POST['users_id'] ?? 0);
        $chatType = (string) ($_POST['chat_type'] ?? '');

        if ($id < 1 || $name === '' || strlen($name) > 120
            || strlen($chatId) > 64
            || (!preg_match('/^-?\\d{1,20}$/', $chatId) && !preg_match('/^@[A-Za-z0-9_]{5,32}$/', $chatId))
            || !in_array($chatType, ['private', 'group', 'supergroup', 'channel'], true)
            || $userId < 0
        ) {
            throw new RuntimeException('Revise los datos del destino.');
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
        $updated = $DB->doQuery(
            "UPDATE glpi_plugin_telegram_destinations
             SET name = '" . $DB->escape($name) . "',
                 chat_id = '" . $DB->escape($chatId) . "',
                 users_id = {$userIdSql},
                 chat_type = '" . $DB->escape($chatType) . "'
             WHERE id = {$id}"
        );
        if ($updated === false) {
            throw new RuntimeException('No se pudo actualizar el destino.');
        }
        Session::addMessageAfterRedirect('Destino Telegram actualizado.', false, INFO);
    } elseif ($action === 'add_ad_agent') {
        $agentId = trim((string) ($_POST['agent_id'] ?? ''));
        $secret = AgentRegistry::register($agentId, trim((string) ($_POST['agent_name'] ?? '')), trim((string) ($_POST['domain_controller'] ?? '')));
        Session::addMessageAfterRedirect('Agente pendiente. Copie ahora este secreto una sola vez para ' . $agentId . ': ' . $secret, false, INFO);
    } elseif ($action === 'approve_ad_agent') {
        AgentRegistry::approve(trim((string) ($_POST['agent_id'] ?? '')));
        Session::addMessageAfterRedirect('Agente AD aprobado.', false, INFO);
    } elseif ($action === 'revoke_ad_agent') {
        AgentRegistry::revoke(trim((string) ($_POST['agent_id'] ?? '')));
        Session::addMessageAfterRedirect('Agente AD revocado.', false, INFO);
    } elseif ($action === 'set_default_ad_agent') {
        AgentRegistry::setDefault(trim((string) ($_POST['agent_id'] ?? '')));
        Session::addMessageAfterRedirect('Agente predeterminado actualizado.', false, INFO);
    } elseif ($action === 'save_ad_authorization') {
        $userId = (int) ($_POST['users_id'] ?? 0);
        $canUnlock = isset($_POST['can_unlock']) ? 1 : 0;
        $canView = (isset($_POST['can_view']) || $canUnlock) ? 1 : 0;
        $isActive = isset($_POST['is_active_present']) ? (isset($_POST['is_active']) ? 1 : 0) : 1;

        if ($userId < 1) {
            throw new RuntimeException('Seleccione un usuario GLPI válido.');
        }
        $userCheck = $DB->doQuery(
            "SELECT id FROM glpi_users
             WHERE id = {$userId} AND is_active = 1 AND is_deleted = 0 LIMIT 1"
        );
        if (!$userCheck || $DB->numrows($userCheck) !== 1) {
            throw new RuntimeException('El usuario GLPI no existe o está inactivo.');
        }
        if ($canView === 0 && $canUnlock === 0) {
            $isActive = 0;
        }
        if ($DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_ad_authorizations
                (users_id, can_view, can_unlock, is_active)
             VALUES ({$userId}, {$canView}, {$canUnlock}, {$isActive})
             ON DUPLICATE KEY UPDATE
                can_view = VALUES(can_view),
                can_unlock = VALUES(can_unlock),
                is_active = VALUES(is_active)"
        ) === false) {
            throw new RuntimeException('No se pudo guardar la autorización AD.');
        }
        Session::addMessageAfterRedirect('Permisos de Active Directory actualizados.', false, INFO);
    } elseif ($action === 'add_subscription') {
        $sourceMode = (string) ($_POST['source_mode'] ?? 'glpi');
        $eventMode = (string) ($_POST['event_mode'] ?? '');
        $source = $sourceMode === 'glpi' ? 'glpi' : trim((string) ($_POST['source_custom'] ?? ''));
        $event = $eventMode === 'custom' ? trim((string) ($_POST['event_custom'] ?? '')) : $eventMode;
        $destinationId = (int) ($_POST['destination_id'] ?? 0);

        if (!in_array($sourceMode, ['glpi', 'custom'], true)
            || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $source)
            || ($event !== '*' && !preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $event))
            || ($event === '*' && $sourceMode !== 'custom')
            || ($sourceMode === 'glpi' && $eventMode === 'custom')
            || $destinationId < 1
        ) {
            throw new RuntimeException('Revise el módulo, evento y destino seleccionado. Para eventos externos, indique sus identificadores válidos.');
        }

        $destinationCheck = $DB->doQuery(
            "SELECT id FROM glpi_plugin_telegram_destinations
             WHERE id = {$destinationId} AND is_active = 1 LIMIT 1"
        );
        if (!$destinationCheck || $DB->numrows($destinationCheck) !== 1) {
            throw new RuntimeException('El destino seleccionado no existe o está inactivo.');
        }

        if ($DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_subscriptions (`source`, `event`, destination_id)
             VALUES ('" . $DB->escape($source) . "', '" . $DB->escape($event) . "', {$destinationId})
             ON DUPLICATE KEY UPDATE is_active = 1"
        ) === false) {
            throw new RuntimeException('No se pudo guardar la suscripción.');
        }
        Session::addMessageAfterRedirect($event === '*' ? 'Suscripción general del plugin guardada: recibirá todos sus eventos.' : 'Suscripción de evento guardada.', false, INFO);
    } elseif ($action === 'toggle_destination') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Destino no válido.');
        }
        if ($DB->doQuery("UPDATE glpi_plugin_telegram_destinations SET is_active = IF(is_active = 1, 0, 1) WHERE id = {$id}") === false
            || $DB->affectedRows() !== 1
        ) {
            throw new RuntimeException('No se pudo cambiar el estado del destino.');
        }
        Session::addMessageAfterRedirect('Estado del destino actualizado.', false, INFO);
    } elseif ($action === 'toggle_subscription') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Suscripción no válida.');
        }
        if ($DB->doQuery("UPDATE glpi_plugin_telegram_subscriptions SET is_active = IF(is_active = 1, 0, 1) WHERE id = {$id}") === false
            || $DB->affectedRows() !== 1
        ) {
            throw new RuntimeException('No se pudo cambiar el estado de la suscripción.');
        }
        Session::addMessageAfterRedirect('Estado de la suscripción actualizado.', false, INFO);
    } elseif ($action === 'delete_destination') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Destino no válido.');
        }
        $activeQueue = $DB->doQuery(
            "SELECT COUNT(*) AS total FROM glpi_plugin_telegram_queue
             WHERE destination_id = {$id} AND status IN ('pending', 'processing', 'retry')"
        );
        $activeRow = $activeQueue ? $DB->fetchAssoc($activeQueue) : false;
        if (!$activeRow || (int) $activeRow['total'] > 0) {
            throw new RuntimeException('No se puede eliminar el destino porque tiene notificaciones pendientes, en proceso o para reintento. Espere a que terminen.');
        }
        $exists = $DB->doQuery("SELECT id FROM glpi_plugin_telegram_destinations WHERE id = {$id} LIMIT 1");
        if (!$exists || $DB->numrows($exists) !== 1) {
            throw new RuntimeException('El destino ya no existe.');
        }
        if ($DB->doQuery("DELETE FROM glpi_plugin_telegram_subscriptions WHERE destination_id = {$id}") === false
            || $DB->doQuery("DELETE FROM glpi_plugin_telegram_destinations WHERE id = {$id}") === false
            || $DB->affectedRows() !== 1
        ) {
            throw new RuntimeException('No se pudo eliminar el destino y sus reglas asociadas.');
        }
        Session::addMessageAfterRedirect('Destino eliminado. El historial de entregas se conservó.', false, INFO);
    } elseif ($action === 'delete_subscription') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1 || $DB->doQuery("DELETE FROM glpi_plugin_telegram_subscriptions WHERE id = {$id}") === false
            || $DB->affectedRows() !== 1
        ) {
            throw new RuntimeException('La regla no existe o no se pudo eliminar.');
        }
        Session::addMessageAfterRedirect('Regla de notificación eliminada.', false, INFO);
    } elseif ($action === 'delete_ad_authorization') {
        $userId = (int) ($_POST['users_id'] ?? 0);
        if ($userId < 1 || $DB->doQuery("DELETE FROM glpi_plugin_telegram_ad_authorizations WHERE users_id = {$userId}") === false
            || $DB->affectedRows() !== 1
        ) {
            throw new RuntimeException('La autorización AD no existe o no se pudo eliminar.');
        }
        Session::addMessageAfterRedirect('Autorización AD eliminada.', false, INFO);
    } elseif ($action === 'delete_ad_agent') {
        $agentId = trim((string) ($_POST['agent_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId)) {
            throw new RuntimeException('Identificador de agente no válido.');
        }
        $safeAgent = $DB->escape($agentId);
        $pending = $DB->doQuery(
            "SELECT COUNT(*) AS total FROM glpi_plugin_telegram_ad_requests
             WHERE target_agent_id = '{$safeAgent}' AND status IN ('pending_confirmation', 'queued', 'claimed')"
        );
        $pendingRow = $pending ? $DB->fetchAssoc($pending) : false;
        if (!$pendingRow || (int) $pendingRow['total'] > 0) {
            throw new RuntimeException('No se puede eliminar el agente porque tiene solicitudes pendientes, en cola o reclamadas.');
        }
        $agentExists = $DB->doQuery("SELECT agent_id FROM glpi_plugin_telegram_ad_agents WHERE agent_id = '{$safeAgent}' LIMIT 1");
        if (!$agentExists || $DB->numrows($agentExists) !== 1) {
            throw new RuntimeException('El agente ya no existe.');
        }
        $secretKey = $DB->escape('ad_agent_hmac_' . hash('sha256', $agentId));
        $isDefault = AgentRegistry::getDefaultAgentId(false) === $agentId;
        if ($DB->doQuery("DELETE FROM glpi_plugin_telegram_configs WHERE config_key = '{$secretKey}'") === false) {
            throw new RuntimeException('No se pudo retirar el secreto cifrado del agente.');
        }
        if ($isDefault && $DB->doQuery("DELETE FROM glpi_plugin_telegram_configs WHERE config_key = 'ad_default_agent_id'") === false) {
            throw new RuntimeException('No se pudo retirar la selección de agente predeterminado.');
        }
        if ($DB->doQuery("DELETE FROM glpi_plugin_telegram_ad_agents WHERE agent_id = '{$safeAgent}'") === false
            || $DB->affectedRows() !== 1
        ) {
            throw new RuntimeException('No se pudo eliminar el agente AD.');
        }
        Session::addMessageAfterRedirect('Agente eliminado y secreto cifrado retirado. Se conservaron las solicitudes y auditorías históricas.', false, INFO);
    } elseif ($action === 'delete_queue') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Registro de cola no válido.');
        }
        $deleted = $DB->doQuery(
            "DELETE q, l FROM glpi_plugin_telegram_queue q
             LEFT JOIN glpi_plugin_telegram_delivery_logs l ON l.queue_id = q.id
             WHERE q.id = {$id} AND q.status IN ('sent', 'failed')"
        );
        if ($deleted === false || $DB->affectedRows() < 1) {
            throw new RuntimeException('Solo se pueden eliminar notificaciones enviadas o fallidas. Los registros pendientes, en proceso o para reintento se conservan.');
        }
        Session::addMessageAfterRedirect('Registro de cola e intentos asociados eliminados.', false, INFO);
    } elseif ($action === 'retry_queue') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('Registro de cola no válido.');
        }
        if ($DB->doQuery(
            "UPDATE glpi_plugin_telegram_queue
             SET status = 'retry', attempts = 0, next_attempt_at = NOW(),
                 processing_at = NULL, last_error = NULL
             WHERE id = {$id} AND status IN ('failed', 'retry')"
        ) === false || $DB->affectedRows() !== 1) {
            throw new RuntimeException('La notificación no existe o no está disponible para reintento.');
        }
        Session::addMessageAfterRedirect('Notificación programada nuevamente.', false, INFO);
    } else {
        throw new RuntimeException('Acción no reconocida.');
    }
} catch (Throwable $exception) {
    Session::addMessageAfterRedirect('FML Telegram: ' . $exception->getMessage(), false, ERROR);
}

$returnSection = 'home';
$referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$refererQuery = parse_url($referer, PHP_URL_QUERY);
if (is_string($refererQuery)) {
    parse_str($refererQuery, $refererParams);
    $candidate = (string) ($refererParams['section'] ?? 'home');
    if (in_array($candidate, ['home', 'notifications', 'queue', 'ad'], true)) {
        $returnSection = $candidate;
    }
}
Html::redirect($CFG_GLPI['root_doc'] . '/plugins/telegram/front/config.php?section=' . rawurlencode($returnSection));
