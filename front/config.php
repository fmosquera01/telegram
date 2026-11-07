<?php

use GlpiPlugin\Telegram\SecretStore;
use GlpiPlugin\Telegram\ConfigPage;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

Html::header(__('FML Telegram', 'telegram'), $_SERVER['PHP_SELF'], 'plugins', ConfigPage::class, 'config');

function telegram_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$csrf = Session::getNewCSRFToken();
$tokenConfigured = false;
try {
    $tokenConfigured = SecretStore::getBotToken() !== null;
} catch (Throwable $exception) {
    $tokenConfigured = false;
}

echo '<div class="container-fluid">';
echo '<h2>FML Telegram</h2>';
echo '<p>Servicio central de notificaciones Telegram. El token se cifra y nunca se vuelve a mostrar.</p>';

echo '<section class="mb-4">';
echo '<h3>Configuración del bot</h3>';
echo '<p>Estado del token: <strong>' . ($tokenConfigured ? 'Configurado' : 'No configurado / clave no disponible') . '</strong></p>';
echo '<form method="post" action="config.form.php" class="card card-body">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . telegram_h($csrf) . '">';
echo '<input type="hidden" name="action" value="save_token">';
echo '<label for="bot_token">Token del bot (BotFather)</label>';
echo '<input class="form-control mb-2" type="password" id="bot_token" name="bot_token" autocomplete="new-password" maxlength="256" placeholder="Pegue el token para guardarlo o reemplazarlo">';
echo '<button class="btn btn-primary" type="submit">Guardar token cifrado</button>';
echo '</form>';
echo '<form method="post" action="config.form.php" class="mt-2">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . telegram_h(Session::getNewCSRFToken()) . '">';
echo '<input type="hidden" name="action" value="test_bot">';
echo '<button class="btn btn-secondary" type="submit" ' . ($tokenConfigured ? '' : 'disabled') . '>Probar conexión con Telegram</button>';
echo '</form></section>';

echo '<section class="mb-4">';
echo '<h3>Destinos Telegram</h3>';
echo '<form method="post" action="config.form.php" class="card card-body mb-3">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . telegram_h(Session::getNewCSRFToken()) . '">';
echo '<input type="hidden" name="action" value="add_destination">';
echo '<div class="row g-2">';
echo '<div class="col-md-2"><label>Nombre</label><input class="form-control" name="name" maxlength="120" required></div>';
echo '<div class="col-md-2"><label>Alias único</label><input class="form-control" name="alias" maxlength="120" pattern="[A-Za-z0-9_-]+" required></div>';
echo '<div class="col-md-2"><label>Chat ID</label><input class="form-control" name="chat_id" maxlength="64" required></div>';
echo '<div class="col-md-3"><label>Usuario GLPI (opcional)</label><select class="form-select" name="users_id"><option value="0">Sin asociación</option>';
$users = $DB->doQuery('SELECT id, name, firstname, realname FROM glpi_users WHERE is_active = 1 AND is_deleted = 0 ORDER BY realname, firstname LIMIT 1000');
if ($users) {
    while ($user = $DB->fetchAssoc($users)) {
        $displayName = trim($user['firstname'] . ' ' . $user['realname']);
        if ($displayName === '') {
            $displayName = $user['name'];
        }
        echo '<option value="' . (int) $user['id'] . '">' . telegram_h($displayName) . ' (' . telegram_h($user['name']) . ')</option>';
    }
}
echo '</select></div>';
echo '<div class="col-md-2"><label>Tipo</label><select class="form-select" name="chat_type"><option value="private">Privado</option><option value="group">Grupo</option><option value="supergroup">Supergrupo</option><option value="channel">Canal</option></select></div>';
echo '<div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary" type="submit">Agregar</button></div>';
echo '</div></form>';

global $DB;
$destinations = $DB->doQuery(
    'SELECT d.id, d.name, d.alias, d.chat_id, d.chat_type, d.is_active,
            u.name AS glpi_login, u.firstname, u.realname
     FROM glpi_plugin_telegram_destinations d
     LEFT JOIN glpi_users u ON u.id = d.users_id
     ORDER BY d.name'
);
echo '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Nombre</th><th>Alias</th><th>Chat ID</th><th>Usuario GLPI</th><th>Tipo</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
if ($destinations) {
    while ($destination = $DB->fetchAssoc($destinations)) {
         $linkedUser = trim((string) ($destination['firstname'] ?? '') . ' ' . (string) ($destination['realname'] ?? ''));
        if ($linkedUser === '') {
            $linkedUser = (string) ($destination['glpi_login'] ?? '');
        }
        echo '<tr><td>' . telegram_h($destination['name']) . '</td><td>' . telegram_h($destination['alias']) . '</td><td>' . telegram_h($destination['chat_id']) . '</td><td>' . telegram_h($linkedUser) . '</td><td>' . telegram_h($destination['chat_type']) . '</td><td>' . ((int) $destination['is_active'] === 1 ? 'Activo' : 'Inactivo') . '</td><td>';
        echo '<form method="post" action="config.form.php"><input type="hidden" name="_glpi_csrf_token" value="' . telegram_h(Session::getNewCSRFToken()) . '"><input type="hidden" name="action" value="toggle_destination"><input type="hidden" name="id" value="' . (int) $destination['id'] . '"><button class="btn btn-sm btn-outline-secondary" type="submit">' . ((int) $destination['is_active'] === 1 ? 'Desactivar' : 'Activar') . '</button></form></td></tr>';
    }
}
echo '</tbody></table></div></section>';

echo '<section class="mb-4">';
echo '<h3>Suscripciones de eventos</h3>';
echo '<p class="text-muted">Eventos nativos disponibles: origen <code>glpi</code> y eventos <code>ticket.created</code>, <code>ticket.assigned</code>, <code>ticket.status_changed</code>, <code>ticket.priority_changed</code>.</p>';
echo '<form method="post" action="config.form.php" class="card card-body mb-3">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . telegram_h(Session::getNewCSRFToken()) . '">';
echo '<input type="hidden" name="action" value="add_subscription">';
echo '<div class="row g-2">';
echo '<div class="col-md-3"><label>Origen</label><input class="form-control" name="source" maxlength="80" placeholder="glpi o identificador_plugin" required></div>';
echo '<div class="col-md-3"><label>Evento</label><input class="form-control" name="event" maxlength="120" placeholder="ticket.created" required></div>';
echo '<div class="col-md-4"><label>Destino</label><select class="form-select" name="destination_id" required><option value="">Seleccione...</option>';
$destinations = $DB->doQuery('SELECT id, name, alias FROM glpi_plugin_telegram_destinations WHERE is_active = 1 ORDER BY name');
if ($destinations) {
    while ($destination = $DB->fetchAssoc($destinations)) {
        echo '<option value="' . (int) $destination['id'] . '">' . telegram_h($destination['name']) . ' (' . telegram_h($destination['alias']) . ')</option>';
    }
}
echo '</select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary" type="submit">Suscribir</button></div>';
echo '</div></form>';

$subscriptions = $DB->doQuery(
    'SELECT s.id, s.`source`, s.`event`, d.name AS destination_name, s.is_active
     FROM glpi_plugin_telegram_subscriptions s
     INNER JOIN glpi_plugin_telegram_destinations d ON d.id = s.destination_id
     ORDER BY s.source, s.event, d.name'
);
echo '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Origen</th><th>Evento</th><th>Destino</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
if ($subscriptions) {
    while ($subscription = $DB->fetchAssoc($subscriptions)) {
        echo '<tr><td>' . telegram_h($subscription['source']) . '</td><td>' . telegram_h($subscription['event']) . '</td><td>' . telegram_h($subscription['destination_name']) . '</td><td>' . ((int) $subscription['is_active'] === 1 ? 'Activa' : 'Inactiva') . '</td><td>';
        echo '<form method="post" action="config.form.php"><input type="hidden" name="_glpi_csrf_token" value="' . telegram_h(Session::getNewCSRFToken()) . '"><input type="hidden" name="action" value="toggle_subscription"><input type="hidden" name="id" value="' . (int) $subscription['id'] . '"><button class="btn btn-sm btn-outline-secondary" type="submit">' . ((int) $subscription['is_active'] === 1 ? 'Desactivar' : 'Activar') . '</button></form></td></tr>';
    }
}
echo '</tbody></table></div></section>';

echo '<section class="mb-4">';
echo '<h3>Historial de entregas recientes</h3>';
$history = $DB->doQuery(
    'SELECT id, date_creation, date_sent, source, event, subject, chat_id, status, attempts, last_error
     FROM glpi_plugin_telegram_queue ORDER BY id DESC LIMIT 100'
);
echo '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>ID</th><th>Creada</th><th>Origen</th><th>Evento</th><th>Asunto</th><th>Destino</th><th>Estado</th><th>Intentos</th><th>Último error</th><th>Acción</th></tr></thead><tbody>';
if ($history) {
    while ($entry = $DB->fetchAssoc($history)) {
        echo '<tr><td>' . (int) $entry['id'] . '</td><td>' . telegram_h($entry['date_creation']) . '</td><td>' . telegram_h($entry['source']) . '</td><td>' . telegram_h($entry['event']) . '</td><td>' . telegram_h($entry['subject']) . '</td><td>' . telegram_h($entry['chat_id']) . '</td><td>' . telegram_h($entry['status']) . '</td><td>' . (int) $entry['attempts'] . '</td><td>' . telegram_h($entry['last_error']) . '</td><td>';
        if (in_array($entry['status'], ['failed', 'retry'], true)) {
            echo '<form method="post" action="config.form.php"><input type="hidden" name="_glpi_csrf_token" value="' . telegram_h(Session::getNewCSRFToken()) . '"><input type="hidden" name="action" value="retry_queue"><input type="hidden" name="id" value="' . (int) $entry['id'] . '"><button class="btn btn-sm btn-outline-primary" type="submit">Reintentar</button></form>';
        }
        echo '</td></tr>';
    }
}
echo '</tbody></table></div></section>';

echo '<p class="alert alert-info">La entrega se realiza mediante la tarea automática de GLPI. Configure el modo cron del sistema para una ejecución regular.</p>';
echo '</div>';

Html::footer();
