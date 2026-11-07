<?php

use GlpiPlugin\Telegram\SecretStore;
use GlpiPlugin\Telegram\ConfigPage;

include('../../../inc/includes.php');

global $DB;

Session::checkRight('config', UPDATE);

Html::header(__('FML Telegram', 'telegram'), $_SERVER['PHP_SELF'], 'plugins', ConfigPage::class, 'config');

function plugin_telegram_escape($value): string
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
echo '<input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '">';
echo '<input type="hidden" name="action" value="save_token">';
echo '<label for="bot_token">Token del bot (BotFather)</label>';
echo '<input class="form-control mb-2" type="password" id="bot_token" name="bot_token" autocomplete="new-password" maxlength="256" placeholder="Pegue el token para guardarlo o reemplazarlo">';
echo '<button class="btn btn-primary" type="submit">Guardar token cifrado</button>';
echo '</form>';
echo '<form method="post" action="config.form.php" class="mt-2">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '">';
echo '<input type="hidden" name="action" value="test_bot">';
echo '<button class="btn btn-secondary" type="submit" ' . ($tokenConfigured ? '' : 'disabled') . '>Probar conexión con Telegram</button>';
echo '</form></section>';

echo '<section class="mb-4">';
echo '<h3>Destinos Telegram</h3>';
echo '<form method="post" action="config.form.php" class="card card-body mb-3">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '">';
echo '<input type="hidden" name="action" value="add_destination">';
echo '<div class="row g-2">';
echo '<div class="col-md-2"><label>Nombre</label><input class="form-control" name="name" maxlength="120" required></div>';
echo '<div class="col-md-2"><label>Alias único</label><input class="form-control" name="alias" maxlength="120" pattern="[A-Za-z0-9_-]+" required></div>';
echo '<div class="col-md-2"><label>Chat ID</label><input class="form-control" name="chat_id" maxlength="64" required></div>';
echo '<div class="col-md-3"><label>Usuario GLPI (opcional)</label>';
Dropdown::show('User', [
    'name'                 => 'users_id',
    'value'                => 0,
    'display_emptychoice'  => true,
    'emptylabel'           => 'Sin asociación',
]);
echo '</div>';
echo '<div class="col-md-2"><label>Tipo</label><select class="form-select" name="chat_type"><option value="private">Privado</option><option value="group">Grupo</option><option value="supergroup">Supergrupo</option><option value="channel">Canal</option></select></div>';
echo '<div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary" type="submit">Agregar</button></div>';
echo '</div></form>';

$destinations = $DB->doQuery(
    'SELECT d.id, d.name, d.alias, d.chat_id, d.chat_type, d.is_active, d.users_id,
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
        echo '<tr><td>' . plugin_telegram_escape($destination['name']) . '</td><td>' . plugin_telegram_escape($destination['alias']) . '</td><td>' . plugin_telegram_escape($destination['chat_id']) . '</td><td>' . plugin_telegram_escape($linkedUser) . '</td><td>' . plugin_telegram_escape($destination['chat_type']) . '</td><td>' . ((int) $destination['is_active'] === 1 ? 'Activo' : 'Inactivo') . '</td><td>';
        echo '<details><summary>Editar</summary><form method="post" action="config.form.php" class="card card-body mt-2">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '"><input type="hidden" name="action" value="update_destination"><input type="hidden" name="id" value="' . (int) $destination['id'] . '">';
        echo '<label>Nombre</label><input class="form-control mb-1" name="name" maxlength="120" required value="' . plugin_telegram_escape($destination['name']) . '">';
        echo '<label>Chat ID / @canal</label><input class="form-control mb-1" name="chat_id" maxlength="64" required value="' . plugin_telegram_escape($destination['chat_id']) . '">';
        echo '<label>Usuario GLPI (opcional)</label>';
        Dropdown::show('User', [
            'name'                 => 'users_id',
            'value'                => (int) ($destination['users_id'] ?? 0),
            'display_emptychoice'  => true,
            'emptylabel'           => 'Sin asociación',
        ]);
        echo '<label>Tipo</label><select class="form-select mb-2" name="chat_type">';
        foreach (['private' => 'Privado', 'group' => 'Grupo', 'supergroup' => 'Supergrupo', 'channel' => 'Canal'] as $type => $label) {
            echo '<option value="' . $type . '"' . ($destination['chat_type'] === $type ? ' selected' : '') . '>' . $label . '</option>';
        }
        echo '</select><button class="btn btn-sm btn-primary" type="submit">Guardar cambios</button></form></details>';
        if ($tokenConfigured && (int) $destination['is_active'] === 1) {
            echo '<form method="post" action="config.form.php" class="d-inline"><input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '"><input type="hidden" name="action" value="test_destination"><input type="hidden" name="id" value="' . (int) $destination['id'] . '"><button class="btn btn-sm btn-outline-primary" type="submit">Probar</button></form> ';
        }
        echo '<form method="post" action="config.form.php" class="d-inline"><input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '"><input type="hidden" name="action" value="toggle_destination"><input type="hidden" name="id" value="' . (int) $destination['id'] . '"><button class="btn btn-sm btn-outline-secondary" type="submit">' . ((int) $destination['is_active'] === 1 ? 'Desactivar' : 'Activar') . '</button></form></td></tr>';
    }
}
echo '</tbody></table></div></section>';

echo '<section class="mb-4">';
echo '<h3>Reglas de notificación</h3>';
echo '<p class="text-muted">Seleccione el módulo, el evento y el destino. Los eventos de GLPI se presentan con nombres comprensibles; los plugins externos pueden registrar sus propios códigos.</p>';
echo '<div class="alert alert-info">Asignación individual: al asignar un ticket a un técnico, FML Telegram buscará automáticamente su destino privado vinculado al usuario GLPI. También se enviará a los grupos suscritos a <code>ticket.assigned</code>.</div>';
echo '<form method="post" action="config.form.php" class="card card-body mb-3">';
echo '<input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '">';
echo '<input type="hidden" name="action" value="add_subscription">';
echo '<div class="row g-2">';
echo '<div class="col-md-3"><label>Módulo / Origen</label><select class="form-select" name="source_mode" id="telegram_source_mode"><option value="glpi">GLPI</option><option value="custom">Otro plugin</option></select></div>';
echo '<div class="col-md-3" id="telegram_custom_source_wrap" style="display:none"><label>Identificador del plugin</label><input class="form-control" name="source_custom" maxlength="80" placeholder="mi_plugin"></div>';
echo '<div class="col-md-3"><label>Evento</label><select class="form-select" name="event_mode" id="telegram_event_mode">';
$eventOptions = [
    'ticket.created' => 'Ticket creado',
    'ticket.assigned' => 'Ticket asignado',
    'ticket.status_changed' => 'Cambio de estado',
    'ticket.priority_changed' => 'Cambio de prioridad',
    'ticket.sla_changed' => 'Cambio de SLA',
    'ticket.sla_due' => 'SLA próximo a vencer',
    'ticket.sla_overdue' => 'SLA vencido',
    'inventory.received' => 'Inventario recibido',
];
foreach ($eventOptions as $eventCode => $eventLabel) {
    echo '<option value="' . plugin_telegram_escape($eventCode) . '">' . plugin_telegram_escape($eventLabel) . '</option>';
}
echo '<option value="custom">Evento de otro plugin</option></select></div>';
echo '<div class="col-md-3" id="telegram_custom_event_wrap" style="display:none"><label>Código del evento</label><input class="form-control" name="event_custom" maxlength="120" placeholder="hardware.changed"></div>';
echo '<div class="col-md-3"><label>Destino</label><select class="form-select" name="destination_id" required><option value="">Seleccione...</option>';
$destinations = $DB->doQuery('SELECT id, name, alias FROM glpi_plugin_telegram_destinations WHERE is_active = 1 ORDER BY name');
if ($destinations) {
    while ($destination = $DB->fetchAssoc($destinations)) {
        echo '<option value="' . (int) $destination['id'] . '">' . plugin_telegram_escape($destination['name']) . ' (' . plugin_telegram_escape($destination['alias']) . ')</option>';
    }
}
echo '</select></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary" type="submit">Crear regla</button></div>';
echo '</div></form>';
echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    const sourceMode = document.getElementById("telegram_source_mode");
    const eventMode = document.getElementById("telegram_event_mode");
    const sourceWrap = document.getElementById("telegram_custom_source_wrap");
    const eventWrap = document.getElementById("telegram_custom_event_wrap");
    const updateFields = function () {
        sourceWrap.style.display = sourceMode.value === "custom" ? "" : "none";
        eventWrap.style.display = eventMode.value === "custom" ? "" : "none";
    };
    sourceMode.addEventListener("change", updateFields);
    eventMode.addEventListener("change", updateFields);
    updateFields();
});
</script>';

$subscriptions = $DB->doQuery(
    'SELECT s.id, s.`source`, s.`event`, d.name AS destination_name, s.is_active
     FROM glpi_plugin_telegram_subscriptions s
     INNER JOIN glpi_plugin_telegram_destinations d ON d.id = s.destination_id
     ORDER BY s.`source`, s.`event`, d.name'
);
echo '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Origen</th><th>Evento</th><th>Destino</th><th>Estado</th><th>Acción</th></tr></thead><tbody>';
if ($subscriptions) {
    while ($subscription = $DB->fetchAssoc($subscriptions)) {
        echo '<tr><td>' . plugin_telegram_escape($subscription['source']) . '</td><td>' . plugin_telegram_escape($subscription['event']) . '</td><td>' . plugin_telegram_escape($subscription['destination_name']) . '</td><td>' . ((int) $subscription['is_active'] === 1 ? 'Activa' : 'Inactiva') . '</td><td>';
        echo '<form method="post" action="config.form.php"><input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '"><input type="hidden" name="action" value="toggle_subscription"><input type="hidden" name="id" value="' . (int) $subscription['id'] . '"><button class="btn btn-sm btn-outline-secondary" type="submit">' . ((int) $subscription['is_active'] === 1 ? 'Desactivar' : 'Activar') . '</button></form></td></tr>';
    }
}
echo '</tbody></table></div></section>';

echo '<section class="mb-4">';
echo '<h3>Historial de entregas</h3>';
$historyPage = max(1, (int) ($_GET['history_page'] ?? 1));
$historyPerPage = 50;
$historyStatus = (string) ($_GET['history_status'] ?? '');
$historySource = trim((string) ($_GET['history_source'] ?? ''));
$allowedStatuses = ['pending', 'processing', 'retry', 'sent', 'failed'];
if (!in_array($historyStatus, $allowedStatuses, true)) {
    $historyStatus = '';
}
$historyWhere = [];
if ($historyStatus !== '') {
    $historyWhere[] = "status = '" . $DB->escape($historyStatus) . "'";
}
if ($historySource !== '') {
    $historyWhere[] = "`source` = '" . $DB->escape(substr($historySource, 0, 80)) . "'";
    $historySource = substr($historySource, 0, 80);
}
$historyWhereSql = $historyWhere === [] ? '' : ' WHERE ' . implode(' AND ', $historyWhere);
$countResult = $DB->doQuery('SELECT COUNT(*) AS total FROM glpi_plugin_telegram_queue' . $historyWhereSql);
$countRow = $countResult ? $DB->fetchAssoc($countResult) : ['total' => 0];
$historyTotal = (int) ($countRow['total'] ?? 0);
$historyPages = max(1, (int) ceil($historyTotal / $historyPerPage));
$historyPage = min($historyPage, $historyPages);
$historyOffset = ($historyPage - 1) * $historyPerPage;
echo '<form method="get" class="row g-2 mb-3">';
echo '<div class="col-md-3"><label>Estado</label><select class="form-select" name="history_status"><option value="">Todos</option>';
foreach ($allowedStatuses as $statusOption) {
    echo '<option value="' . $statusOption . '"' . ($historyStatus === $statusOption ? ' selected' : '') . '>' . $statusOption . '</option>';
}
echo '</select></div><div class="col-md-4"><label>Origen</label><input class="form-control" name="history_source" maxlength="80" value="' . plugin_telegram_escape($historySource) . '"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary" type="submit">Filtrar</button></div></form>';
$history = $DB->doQuery(
    'SELECT q.id, q.date_creation, q.date_sent, q.`source`, q.`event`, q.subject, q.chat_id,
            q.status, q.attempts, q.last_error,
            (SELECT COUNT(*) FROM glpi_plugin_telegram_delivery_logs l WHERE l.queue_id = q.id AND l.result_status IN (\'retry\', \'failed\')) AS delivery_count
     FROM glpi_plugin_telegram_queue q' . $historyWhereSql . '
     ORDER BY q.id DESC LIMIT ' . $historyPerPage . ' OFFSET ' . $historyOffset
);
echo '<p class="text-muted">Registros: ' . $historyTotal . ' · Página ' . $historyPage . ' de ' . $historyPages . '</p>';
echo '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>ID</th><th>Creada</th><th>Origen</th><th>Evento</th><th>Asunto</th><th>Destino</th><th>Estado</th><th>Intentos</th><th>Fallos</th><th>Último error</th><th>Acción</th></tr></thead><tbody>';
if ($history) {
    while ($entry = $DB->fetchAssoc($history)) {
        echo '<tr><td>' . (int) $entry['id'] . '</td><td>' . plugin_telegram_escape($entry['date_creation']) . '</td><td>' . plugin_telegram_escape($entry['source']) . '</td><td>' . plugin_telegram_escape($entry['event']) . '</td><td>' . plugin_telegram_escape($entry['subject']) . '</td><td>' . plugin_telegram_escape($entry['chat_id']) . '</td><td>' . plugin_telegram_escape($entry['status']) . '</td><td>' . (int) $entry['attempts'] . '</td><td>' . (int) $entry['delivery_count'] . '</td><td>' . plugin_telegram_escape($entry['last_error']) . '</td><td>';
        if (in_array($entry['status'], ['failed', 'retry'], true)) {
            echo '<form method="post" action="config.form.php"><input type="hidden" name="_glpi_csrf_token" value="' . plugin_telegram_escape($csrf) . '"><input type="hidden" name="action" value="retry_queue"><input type="hidden" name="id" value="' . (int) $entry['id'] . '"><button class="btn btn-sm btn-outline-primary" type="submit">Reintentar</button></form>';
        }
        echo '</td></tr>';
    }
}
echo '</tbody></table></div>';
if ($historyPages > 1) {
    echo '<nav aria-label="Paginación del historial"><ul class="pagination">';
    if ($historyPage > 1) {
        echo '<li class="page-item"><a class="page-link" href="?history_page=' . ($historyPage - 1) . '&history_status=' . rawurlencode($historyStatus) . '&history_source=' . rawurlencode($historySource) . '">Anterior</a></li>';
    }
    if ($historyPage < $historyPages) {
        echo '<li class="page-item"><a class="page-link" href="?history_page=' . ($historyPage + 1) . '&history_status=' . rawurlencode($historyStatus) . '&history_source=' . rawurlencode($historySource) . '">Siguiente</a></li>';
    }
    echo '</ul></nav>';
}
echo '</section>';

echo '<section class="mb-4">';
echo '<h3>Detalle de intentos recientes</h3>';
$attempts = $DB->doQuery(
    'SELECT l.id, l.queue_id, l.attempt_number, l.result_status, l.telegram_message_id,
            l.error_message, l.date_creation, q.subject, q.`source`, q.`event`
     FROM glpi_plugin_telegram_delivery_logs l
     INNER JOIN glpi_plugin_telegram_queue q ON q.id = l.queue_id
     ORDER BY l.id DESC LIMIT 100'
);
echo '<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr><th>Fecha</th><th>Cola</th><th>Origen</th><th>Evento</th><th>Asunto</th><th>Intento</th><th>Resultado</th><th>Telegram ID</th><th>Error</th></tr></thead><tbody>';
if ($attempts) {
    while ($attempt = $DB->fetchAssoc($attempts)) {
        echo '<tr><td>' . plugin_telegram_escape($attempt['date_creation']) . '</td><td>' . (int) $attempt['queue_id'] . '</td><td>' . plugin_telegram_escape($attempt['source']) . '</td><td>' . plugin_telegram_escape($attempt['event']) . '</td><td>' . plugin_telegram_escape($attempt['subject']) . '</td><td>' . (int) $attempt['attempt_number'] . '</td><td>' . plugin_telegram_escape($attempt['result_status']) . '</td><td>' . plugin_telegram_escape($attempt['telegram_message_id']) . '</td><td>' . plugin_telegram_escape($attempt['error_message']) . '</td></tr>';
    }
}
echo '</tbody></table></div></section>';

echo '<p class="alert alert-info">La entrega se realiza mediante la tarea automática de GLPI. Configure el modo cron del sistema para una ejecución regular.</p>';
echo '</div>';

Html::footer();
