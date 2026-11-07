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

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function doQuery(string $query): bool
    {
        $this->queries[] = $query;
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

require_once __DIR__ . '/../src/NotificationService.php';

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

echo "FML Telegram NotificationService tests passed.\n";
