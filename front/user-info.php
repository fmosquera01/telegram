<?php
/**
 * AJAX endpoint returning safe display fields for a selected GLPI user.
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);
header('Content-Type: application/json; charset=UTF-8');

global $DB;

$userId = (int) ($_GET['users_id'] ?? 0);
if ($userId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Usuario no válido.']);
    exit;
}

$result = $DB->doQuery(
    "SELECT name, firstname, realname
     FROM glpi_users
     WHERE id = {$userId} AND is_active = 1 AND is_deleted = 0
     LIMIT 1"
);
$user = $result ? $DB->fetchAssoc($result) : false;
if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'El usuario no existe o está inactivo.']);
    exit;
}

$login = trim((string) $user['name']);
$displayName = trim((string) $user['firstname'] . ' ' . (string) $user['realname']);
if ($displayName === '') {
    $displayName = $login;
}
$alias = strtolower($login);
$alias = trim((string) preg_replace('/[^a-z0-9_-]+/', '_', $alias), '_-');

if ($displayName === '' || !preg_match('/^[A-Za-z0-9_-]{2,120}$/', $alias)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'No se pudo generar un alias válido desde el usuario GLPI.']);
    exit;
}

echo json_encode([
    'success' => true,
    'name' => $displayName,
    'alias' => $alias,
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
