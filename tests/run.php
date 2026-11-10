<?php

declare(strict_types=1);

/**
 * Lightweight contract tests for NotificationService.
 * Run with: php tests/run.php
 */

final class TelegramTestDatabase
{
    public array $queries = [];
    public array $rows = [];
    public int $affected = 0;
    public bool $duplicateNextInsert = false;
    public bool $slaMode = false;
    public array $ticketRows = [];

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function doQuery(string $query): bool
    {
        $this->queries[] = $query;
        if (str_contains($query, 'SELECT COUNT(*) AS total')) {
            $this->rows = [['total' => $this->slaMode ? 1 : 0]];
            return true;
        }
        if (str_contains($query, 'SELECT config_value')) {
            $this->rows = [['config_value' => '0']];
            return true;
        }
        if (str_contains($query, 'SELECT id, name, time_to_own, time_to_resolve')) {
            $this->rows = $this->ticketRows;
            return true;
        }
        if (str_contains($query, 'SELECT DISTINCT d.id')) {
            $this->rows = [
                ['id' => 7, 'chat_id' => '-1001234567890'],
                ['id' => 8, 'chat_id' => '123456789'],
            ];
            return true;
        }
        if (str_contains($query, 'INSERT IGNORE INTO glpi_plugin_telegram_queue')) {
            $this->affected = $this->duplicateNextInsert ? 0 : 1;
            $this->duplicateNextInsert = false;
            return true;
        }
        return true;
    }

    public function fetchAssoc(bool $result): array|false
    {
        return array_shift($this->rows) ?: false;
    }

    public function numrows(bool $result): int
    {
        return count($this->rows);
    }

    public function affectedRows(): int
    {
        return $this->affected;
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function assertContainsValue(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\nMissing: {$needle}\n");
        exit(1);
    }
}

class CommonGLPI
{
    public static function getTypeName($nb = 0) {}
    public static function getMenuName() {}
    public static function getMenuContent() {}
}

class CommonDBTM
{
    public array $fields = [];
    public array $updates = [];
    public array $oldvalues = [];
}

class CommonITILActor
{
    public const ASSIGN = 2;
}

class Ticket extends CommonDBTM {}
class Ticket_User extends CommonDBTM {}
class Group_Ticket extends CommonDBTM {}

class Group
{
    public array $fields = ['name' => 'Infraestructura'];
    public function getFromDB(int $id): bool { return true; }
}

class User
{
    public array $fields = ['name' => 'tech1', 'firstname' => 'Ana', 'realname' => 'Soporte'];
    public function getFromDB(int $id): bool { return true; }
}

require_once __DIR__ . '/../src/SecretStore.php';
require_once __DIR__ . '/../src/ConfigPage.php';
require_once __DIR__ . '/../src/NotificationService.php';
require_once __DIR__ . '/../src/NativeEvents.php';
require_once __DIR__ . '/../src/Worker.php';

$DB = new TelegramTestDatabase();

$invalid = \GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'plugin_demo',
    'event' => 'hardware.changed',
    'subject' => ' ',
    'message' => 'body',
]);
assertSameValue(0, $invalid['queued'], 'Invalid subject must not enqueue');
assertSameValue(0, count($DB->queries), 'Invalid input must not query the database');

$invalidSource = \GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'invalid source',
    'event' => 'hardware.changed',
    'subject' => 'Invalid source',
    'message' => 'body',
]);
assertSameValue(0, $invalidSource['queued'], 'Source identifiers must use the documented safe format');
assertSameValue(0, count($DB->queries), 'Invalid source must not query the database');

$explicit = \GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'plugin_demo',
    'event' => 'hardware.changed',
    'subject' => 'Hardware changed',
    'message' => 'Serial ABC123',
    'recipients' => ['infraestructura', 'user:42'],
    'deduplication_key' => 'demo:hardware:ABC123',
]);
assertSameValue(2, $explicit['queued'], 'Explicit aliases and linked user must resolve destinations');
$resolveQuery = $DB->queries[0];
assertContainsValue("d.alias IN ('infraestructura')", $resolveQuery, 'Alias recipient must be resolved');
assertContainsValue('d.users_id IN (42)', $resolveQuery, 'GLPI user recipient must be resolved');
$DB->queries = [];
$combined = \GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'glpi',
    'event' => 'ticket.assigned',
    'subject' => 'Ticket assigned',
    'message' => 'Ticket #101 assigned to technician',
    'recipients' => ['user:42'],
    'include_subscriptions' => true,
]);
assertSameValue(2, $combined['queued'], 'Individual technician and subscribed group destinations must both enqueue');
assertContainsValue('d.users_id IN (42)', $DB->queries[0], 'Combined routing must include the linked technician');
assertContainsValue('EXISTS (', $DB->queries[0], 'Combined routing must also include active event subscriptions');
assertContainsValue("s.`event` = 'ticket.assigned'", $DB->queries[0], 'Combined routing must filter subscribed ticket assignment events');
assertContainsValue("s.`event` = 'ticket.assigned'", $DB->queries[0], 'Broadcast assignment subscriptions must use the legacy event');
assertContainsValue('EXISTS (', $DB->queries[0], 'Broadcast assignment subscriptions must be resolved');
assertSameValue(false, str_contains($DB->queries[0], "d.chat_type = 'private' AND d.users_id IN (42)"), 'Broadcast assignment event must not restrict private destinations');


$DB->queries = [];
$DB->duplicateNextInsert = true;
$subscription = \GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'glpi',
    'event' => 'ticket.created',
    'subject' => 'Ticket created',
    'message' => 'Ticket #100',
]);
assertSameValue(1, $subscription['queued'], 'Non-duplicate subscription destination must enqueue');
assertSameValue(1, $subscription['skipped'], 'Duplicate queue insert must be counted as skipped');
assertContainsValue("s.`source` = 'glpi'", $DB->queries[0], 'Subscription routing must filter source');
assertContainsValue("s.`event` = 'ticket.created'", $DB->queries[0], 'Subscription routing must filter event');

$DB->queries = [];
\GlpiPlugin\Telegram\NativeEvents::inventoryReceived((object) [
    'deviceid' => 'SNG-PC-001',
    'itemtype' => 'Computer',
    'version' => '1.19',
]);
assertContainsValue('inventory.received', $DB->queries[0], 'Successful inventory hook must enqueue the inventory event');
$DB->queries = [];
$assignedActor = new Ticket_User();
$assignedActor->fields = ['id' => 654, 'tickets_id' => 6543, 'users_id' => 42, 'type' => CommonITILActor::ASSIGN];
\GlpiPlugin\Telegram\NativeEvents::ticketActorAdded($assignedActor);
$assignedSql = implode("\n", $DB->queries);
assertContainsValue('ticket.assigned', $assignedSql, 'Assigned technician must generate ticket.assigned event');
assertContainsValue('d.users_id IN (42)', $assignedSql, 'Assigned technician event must route to the linked private destination');
assertContainsValue('EXISTS (', $assignedSql, 'Assigned technician event must preserve group subscriptions');
assertContainsValue("s.`event` = 'ticket.assigned'", $assignedSql, 'Legacy assignment event must remain available for broadcast subscriptions');
assertContainsValue("s.`event` = 'ticket.assigned.to_user'", $assignedSql, 'Technician-specific assignment event must also be generated');
assertContainsValue("d.chat_type = 'private' AND d.users_id IN (42)", $assignedSql, 'Technician-specific subscription must target the linked private user');

$DB->queries = [];
$userSpecific = \GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'glpi',
    'event' => 'ticket.assigned.to_user',
    'subject' => 'Assigned to me',
    'message' => 'Ticket #6543 assigned to user 42',
    'recipients' => ['user:42'],
    'include_subscriptions' => true,
]);
assertSameValue(2, $userSpecific['queued'], 'User-specific assignment event must resolve direct and subscribed private destination');
$userSpecificSql = $DB->queries[0];
assertContainsValue("d.chat_type = 'private'", $userSpecificSql, 'User-specific assignment subscriptions must be private');
assertContainsValue('d.users_id IN (42)', $userSpecificSql, 'User-specific assignment subscriptions must match the linked user');
assertContainsValue("s.`event` = 'ticket.assigned.to_user'", $userSpecificSql, 'User-specific event must use its own subscription key');
assertSameValue(false, str_contains($userSpecificSql, "d.chat_type IN ('group', 'supergroup', 'channel')"), 'User-specific event must not broadcast to groups');

$DB->queries = [];
$groupAssignment = \GlpiPlugin\Telegram\NotificationService::enqueue([
    'source' => 'glpi',
    'event' => 'ticket.assigned',
    'subject' => 'Group assignment',
    'message' => 'Ticket #6543 assigned to a group',
]);
assertSameValue(2, $groupAssignment['queued'], 'Group assignment subscriptions must still enqueue');
$groupAssignmentSql = $DB->queries[0];
assertContainsValue("d.chat_type IN ('group', 'supergroup', 'channel')", $groupAssignmentSql, 'Group assignment must route to group and channel destinations');
if (str_contains($groupAssignmentSql, "d.chat_type = 'private'")) {
    fwrite(STDERR, "FAIL: Group assignment without an assigned technician must not match personal destinations.\\n");
    exit(1);
}

$DB->queries = [];
$ticket = new Ticket();
$ticket->fields = [
    'id' => 321,
    'date_mod' => '2026-11-07 12:00:00',
    'slas_id_tto' => 4,
    'slas_id_ttr' => 5,
    'time_to_own' => '2026-11-07 12:30:00',
    'time_to_resolve' => '2026-11-07 15:00:00',
];
$ticket->oldvalues = [
    'slas_id_tto' => 3,
    'slas_id_ttr' => 4,
    'time_to_own' => '2026-11-07 12:00:00',
    'time_to_resolve' => '2026-11-07 14:00:00',
];
$ticket->updates = ['slas_id_ttr', 'time_to_resolve'];
\GlpiPlugin\Telegram\NativeEvents::ticketUpdated($ticket);
assertContainsValue('ticket.sla_changed', $DB->queries[0], 'SLA field updates must enqueue the SLA change event');

$DB->queries = [];
$DB->slaMode = true;
$DB->ticketRows = [[
    'id' => 987,
    'name' => 'SLA test ticket',
    'time_to_own' => date('Y-m-d H:i:s', time() + 600),
    'time_to_resolve' => date('Y-m-d H:i:s', time() - 600),
]];
assertSameValue(true, \GlpiPlugin\Telegram\Worker::cronSla(), 'SLA cron must complete with a valid batch');
$slaQueries = implode("\n", $DB->queries);
assertContainsValue('ticket.sla_due', $slaQueries, 'Upcoming SLA deadline must enqueue a due notification');
assertContainsValue('ticket.sla_overdue', $slaQueries, 'Past SLA deadline must enqueue an overdue notification');
assertContainsValue('sla_scan_cursor', $slaQueries, 'SLA scan must persist its batch cursor');

$DB->queries = [];
assertSameValue(true, \GlpiPlugin\Telegram\Worker::cronSend(), 'Queue worker must stay healthy before bot setup');
$sendQueueQueries = array_filter($DB->queries, static fn ($query) => str_contains($query, 'FROM glpi_plugin_telegram_queue'));
assertSameValue(0, count($sendQueueQueries), 'Queue worker must not consume messages until token configuration exists');

echo "FML Telegram API, native events and SLA cron tests passed.\n";
