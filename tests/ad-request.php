<?php

declare(strict_types=1);

/**
 * Contract tests for the AD request authorization and confirmation service.
 */

final class TelegramAdTestDatabase
{
    public array $queries = [];
    public array $rows = [];
    public int $affected = 0;
    public bool $failQuery = false;
    public string $requestUuid = '';

    public function escape(string $value): string { return addslashes($value); }

    public function doQuery(string $query): bool
    {
        $this->queries[] = $query;
        if ($this->failQuery) return false;
        if (str_contains($query, 'ad_default_agent_id')) {
            $this->rows = [['config_value' => 'SVRSNGPRTG-LAB', 'status' => 'approved', 'is_active' => 1]];
        } elseif (str_contains($query, 'SELECT COUNT(*) AS total') && str_contains($query, 'glpi_plugin_telegram_ad_requests')) {
            $this->rows = [['total' => 0]];
        } elseif (str_contains($query, 'SELECT d.id')) {
            $this->rows = [['id' => 4]];
        } elseif (str_contains($query, 'SELECT a.can_view')) {
            $this->rows = [['can_view' => 1, 'can_unlock' => 1]];
        } elseif (str_contains($query, 'SELECT r.request_uuid') || str_contains($query, 'SELECT request_uuid')) {
            $this->rows = [['request_uuid' => $this->requestUuid, 'requested_by_users_id' => 10]];
        } elseif (str_contains($query, 'UPDATE glpi_plugin_telegram_ad_requests')) {
            $this->affected = 1;
        } elseif (str_contains($query, 'INSERT INTO glpi_plugin_telegram_ad_requests')) {
            $this->affected = 1;
            if (preg_match("/VALUES\\s*\\('([0-9a-f-]{36})'/", $query, $matches)) {
                $this->requestUuid = $matches[1];
            }
        } else {
            $this->rows = [];
        }
        return true;
    }

    public function fetchAssoc(bool $result): array|false { return array_shift($this->rows) ?: false; }
    public function affectedRows(): int { return $this->affected; }
}

function adAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function adAssertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\nMissing: {$needle}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../src/SecretStore.php';
require_once __DIR__ . '/../src/AgentRegistry.php';
require_once __DIR__ . '/../src/AdRequestService.php';

$DB = new TelegramAdTestDatabase();
try {
    \GlpiPlugin\Telegram\AdRequestService::createRequest('unlock_account', 'bad user', 10, '123456');
    fwrite(STDERR, "FAIL: invalid sAMAccountName must be rejected\n");
    exit(1);
} catch (RuntimeException $exception) {
    adAssertSame(0, count($DB->queries), 'Invalid AD account must be rejected before database access');
}

$created = \GlpiPlugin\Telegram\AdRequestService::createRequest('unlock_account', 'usrapp', 10, '123456', 987654321);
adAssertSame('pending_confirmation', $created['status'], 'Unlock requests must require confirmation');
adAssertSame(true, is_string($created['confirmation_token']) && strlen($created['confirmation_token']) === 32, 'Unlock confirmation token must be cryptographically random');
$createSql = implode("\n", $DB->queries);
adAssertContains("d.chat_type = 'private'", $createSql, 'AD requests must only use private Telegram chats');
adAssertContains('d.users_id = 10', $createSql, 'Telegram chat must be bound to the requesting GLPI user');
adAssertContains('a.can_unlock', $createSql, 'Unlock permission must be checked explicitly');
adAssertContains('confirmation_token_hash', $createSql, 'Only a token hash must be stored');
adAssertContains('telegram_update_id', $createSql, 'Telegram update ID must be stored as an idempotency key');
adAssertContains("target_agent_id, status", $createSql, 'New AD requests must be assigned to the configured approved agent');
adAssertContains("'SVRSNGPRTG-LAB'", $createSql, 'New AD requests must be assigned to the default agent identifier');
adAssertContains('INTERVAL 5 MINUTE', $createSql, 'Unlock requests must be rate limited to three per five minutes');
adAssertContains('requested_by_users_id = 10', $createSql, 'Rate limiting must be scoped to the requesting GLPI user');
adAssertSame(false, str_contains($createSql, $created['confirmation_token']), 'Plain confirmation token must not be persisted in SQL');

$DB->queries = [];
adAssertSame(true, \GlpiPlugin\Telegram\AdRequestService::confirmUnlock(
    $created['confirmation_token'],
    '123456'
), 'A valid confirmation must atomically queue the unlock request');
$confirmSql = implode("\n", $DB->queries);
adAssertContains("status = 'pending_confirmation'", $confirmSql, 'Confirmation must only consume a pending request');
adAssertContains('a.can_unlock = 1', $confirmSql, 'Authorization must still be active when confirming');
adAssertContains("d.chat_type = 'private'", $confirmSql, 'The original private destination must still be active at confirmation time');
adAssertContains("status = 'queued'", $confirmSql, 'Confirmed unlock must enter the execution queue');
adAssertContains("confirmation_expires_at > NOW()", $confirmSql, 'Expired confirmation must not be accepted');
adAssertContains("telegram_chat_id = '123456'", $confirmSql, 'Confirmation must originate from the original private chat');

$DB->queries = [];
adAssertSame(false, \GlpiPlugin\Telegram\AdRequestService::confirmUnlock('invalid', '123456'), 'Malformed confirmation data must be rejected');
adAssertSame(0, count($DB->queries), 'Malformed confirmation must not query the database');

$DB->queries = [];
$listRequest = \GlpiPlugin\Telegram\AdRequestService::createRequest('list_locked_accounts', '*', 10, '123456', 987654322);
adAssertSame('queued', $listRequest['status'], 'Locked-account listing must be queued without an unlock confirmation');
$listSql = implode("\n", $DB->queries);
adAssertContains("'list_locked_accounts'", $listSql, 'The new list operation must be persisted in the AD request queue');
adAssertContains('a.can_view', $listSql, 'Listing locked accounts must require view permission');
adAssertSame(null, $listRequest['confirmation_token'], 'Listing must not issue an unlock confirmation token');

echo "FML Telegram AD request authorization and confirmation tests passed.\n";
