<?php

declare(strict_types=1);

/**
 * GLPI 11.0.11 integration smoke test.
 * Executed from the GLPI root after plugin installation and activation.
 */

use GlpiPlugin\Telegram\AdRequestService;
use GlpiPlugin\Telegram\AgentAuth;
use GlpiPlugin\Telegram\ConfigPage;
use GlpiPlugin\Telegram\NativeEvents;
use GlpiPlugin\Telegram\NotificationService;
use GlpiPlugin\Telegram\TelegramUpdateReceiver;
use GlpiPlugin\Telegram\Worker;
use Glpi\Plugin\Hooks;

require __DIR__ . '/../../../vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

global $DB;

function smokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "GLPI smoke test failed: " . $message . "\n");
        exit(1);
    }
}

smokeAssert(class_exists(AdRequestService::class), 'AD request service is not autoloadable');
smokeAssert(class_exists(AgentAuth::class), 'AD agent HMAC service is not autoloadable');
smokeAssert(is_file(__DIR__ . '/../front/ad-agent.php'), 'AD agent HTTP endpoint is missing');
smokeAssert(class_exists(TelegramUpdateReceiver::class), 'Telegram Long Polling receiver is not autoloadable');

$tables = [
    'glpi_plugin_telegram_destinations',
    'glpi_plugin_telegram_subscriptions',
    'glpi_plugin_telegram_configs',
    'glpi_plugin_telegram_queue',
    'glpi_plugin_telegram_delivery_logs',
    'glpi_plugin_telegram_ad_requests',
    'glpi_plugin_telegram_ad_audit',
    'glpi_plugin_telegram_ad_agent_nonces',
    'glpi_plugin_telegram_ad_agents',
    'glpi_plugin_telegram_ad_authorizations',
];
foreach ($tables as $table) {
    smokeAssert($DB->tableExists($table), 'Missing plugin table ' . $table);
}
smokeAssert($DB->fieldExists('glpi_plugin_telegram_ad_requests', 'telegram_update_id'), 'Telegram update idempotency field is missing');
smokeAssert($DB->fieldExists('glpi_plugin_telegram_ad_requests', 'target_agent_id'), 'AD target-agent routing field is missing');
smokeAssert(class_exists(\GlpiPlugin\Telegram\AgentRegistry::class), 'AD agent registry service is not autoloadable');

$legacyAgentCipher = 'ci-legacy-agent-secret-ciphertext';
$DB->doQuery(
    "INSERT INTO glpi_plugin_telegram_configs (config_key, config_value)
     VALUES ('ad_agent_hmac_secret', '" . $DB->escape($legacyAgentCipher) . "')
     ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
);
smokeAssert(\GlpiPlugin\Telegram\AgentRegistry::migrateLegacyAgent(), 'Legacy AD agent secret migration failed');
$legacyAgent = $DB->doQuery(
    "SELECT agent_id, status FROM glpi_plugin_telegram_ad_agents
     WHERE agent_id = 'SVRSNGPRTG-LAB' LIMIT 1"
);
$legacyAgentRow = $legacyAgent ? $DB->fetchAssoc($legacyAgent) : false;
smokeAssert(is_array($legacyAgentRow) && $legacyAgentRow['status'] === 'pending', 'Migrated lab agent must remain pending approval');
$agentSecretKey = 'ad_agent_hmac_' . hash('sha256', 'SVRSNGPRTG-LAB');
$migratedSecret = $DB->doQuery(
    "SELECT config_value FROM glpi_plugin_telegram_configs
     WHERE config_key = '" . $DB->escape($agentSecretKey) . "' LIMIT 1"
);
$migratedSecretRow = $migratedSecret ? $DB->fetchAssoc($migratedSecret) : false;
smokeAssert(is_array($migratedSecretRow) && $migratedSecretRow['config_value'] === $legacyAgentCipher, 'Migration must preserve the existing encrypted HMAC value');
smokeAssert(\GlpiPlugin\Telegram\AgentRegistry::getDefaultAgentId(false) === 'SVRSNGPRTG-LAB', 'Migrated lab agent must be selected as the default target');
smokeAssert(\GlpiPlugin\Telegram\AgentRegistry::getDefaultAgentId() === null, 'Pending agents must not be eligible to receive requests');
\GlpiPlugin\Telegram\AgentRegistry::approve('SVRSNGPRTG-LAB');
smokeAssert(\GlpiPlugin\Telegram\AgentRegistry::getDefaultAgentId() === 'SVRSNGPRTG-LAB', 'Approved default agent must be eligible to receive requests');

global $PLUGIN_HOOKS;
$menuHook = $PLUGIN_HOOKS[Hooks::MENU_TOADD]['telegram']['plugins'] ?? null;
smokeAssert($menuHook === ConfigPage::class, 'FML Telegram menu hook is not registered in GLPI');
$menuEntry = ConfigPage::getMenuContent();
smokeAssert(($menuEntry['page'] ?? '') === '/plugins/telegram/front/config.php?section=home', 'FML Telegram menu points to an unexpected page');

$sendTask = new CronTask();
$slaTask = new CronTask();
smokeAssert($sendTask->getFromDBbyName(Worker::class, 'send'), 'Queue delivery cron task is not registered');
smokeAssert($slaTask->getFromDBbyName(Worker::class, 'sla'), 'SLA cron task is not registered');

$alias = 'ci_smoke_' . bin2hex(random_bytes(3));
$chatId = '-1001234567890';
$inserted = $DB->doQuery(
    "INSERT INTO glpi_plugin_telegram_destinations (name, alias, chat_id, chat_type, is_active)
     VALUES ('CI smoke destination', '" . $DB->escape($alias) . "', '" . $chatId . "', 'supergroup', 1)"
);
smokeAssert($inserted !== false, 'Could not create smoke destination');

$destinationResult = $DB->doQuery(
    "SELECT id FROM glpi_plugin_telegram_destinations WHERE alias = '" . $DB->escape($alias) . "' LIMIT 1"
);
$destination = $destinationResult ? $DB->fetchAssoc($destinationResult) : false;
smokeAssert(is_array($destination), 'Could not read smoke destination');
$destinationId = (int) $destination['id'];

$events = [
    'ticket.created',
    'ticket.sla_changed',
    'ticket.sla_due',
    'ticket.sla_overdue',
    'inventory.received',
];
foreach ($events as $event) {
    $result = $DB->doQuery(
        "INSERT INTO glpi_plugin_telegram_subscriptions (`source`, `event`, destination_id, is_active)
         VALUES ('glpi', '" . $DB->escape($event) . "', " . $destinationId . ", 1)"
    );
    smokeAssert($result !== false, 'Could not subscribe to ' . $event);
}

$apiResult = NotificationService::enqueue([
    'source' => 'plugin_demo',
    'event' => 'integration.smoke',
    'subject' => 'GLPI integration smoke test',
    'message' => 'Queue API is available.',
    'recipients' => [$alias],
]);
smokeAssert(($apiResult['queued'] ?? 0) === 1, 'Public enqueue API did not create a queue row');

$ticket = new Ticket();
$ticket->fields = [
    'id' => 987654,
    'name' => 'FML Telegram smoke ticket',
    'priority' => 3,
    'date_mod' => date('Y-m-d H:i:s'),
];
NativeEvents::ticketAdded($ticket);

$ticketUpdate = new Ticket();
$ticketUpdate->fields = [
    'id' => 987654,
    'date_mod' => date('Y-m-d H:i:s'),
    'slas_id_tto' => 2,
    'slas_id_ttr' => 3,
    'time_to_own' => date('Y-m-d H:i:s', time() + 900),
    'time_to_resolve' => date('Y-m-d H:i:s', time() + 3600),
];
$ticketUpdate->oldvalues = [
    'slas_id_tto' => 1,
    'slas_id_ttr' => 2,
    'time_to_own' => date('Y-m-d H:i:s', time() + 1800),
    'time_to_resolve' => date('Y-m-d H:i:s', time() + 7200),
];
$ticketUpdate->updates = ['slas_id_ttr', 'time_to_resolve'];
NativeEvents::ticketUpdated($ticketUpdate);

NativeEvents::inventoryReceived((object) [
    'deviceid' => 'CI-INV-001',
    'itemtype' => 'Computer',
    'version' => '1.19',
]);

smokeAssert(Worker::cronSla(), 'SLA cron scan failed against the GLPI database');
smokeAssert(Worker::cronSend(), 'Queue worker should remain healthy when no bot token is configured');

$pending = $DB->doQuery(
    "SELECT COUNT(*) AS total FROM glpi_plugin_telegram_queue
     WHERE destination_id = " . $destinationId . " AND status = 'pending'"
);
$pendingRow = $pending ? $DB->fetchAssoc($pending) : false;
smokeAssert(is_array($pendingRow) && (int) $pendingRow['total'] >= 4, 'Expected native and API events to remain queued without a bot token');

echo "FML Telegram GLPI 11.0.11 integration smoke test passed.\n";
