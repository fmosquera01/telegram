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

    public function escape(string $value): string { return addslashes($value); }

    public function doQuery(string $query): bool
    {
        $this->queries[] = $query;
        if ($this->failQuery) return false;
        if (str_contains($query, 'SELECT d.id')) {
            $this->rows = [['id' => 4]];
        } elseif (str_contains($query, 'SELECT a.can_view')) {
            $this->rows = [['can_view' => 1, 'can_unlock' => 1]];
        } elseif (str_contains($query, 'UPDATE glpi_plugin_telegram_ad_requests')) {
            $this->affected = 1;
        } elseif (str_contains($query, 'INSERT INTO glpi_plugin_telegram_ad_requests')) {
            $this->affected = 1;
        } else {
            $this->rows = [];
        }
        return true;
    }

    public function fetchAssoc(bool $result): array|false { return array_shift($this->rows) ?: false; }
    public function affectedRows(): int { return $this->affected; }
}

require_once __DIR__ . '/../src/AdRequestService.php';

$DB = new TelegramAdTestDatabase();
try {
    \GlpiPlugin\Telegram\AdRequestService::createRequest('unlock_account', 'bad user', 10, '123456');
    fwrite(STDERR, "FAIL: invalid sAMAccountName must be rejected\n");
    exit(1);
} catch (RuntimeException $exception) {
    assertSameValue(0, count($DB->queries), 'Invalid AD account must be rejected before database access');
}

$created = \GlpiPlugin\Telegram\AdRequestService::createRequest('unlock_account', 'usrapp', 10, '123456');
assertSameValue('pending_confirmation', $created['status'], 'Unlock requests must require confirmation');
assertSameValue(true, is_string($created['confirmation_token']) && strlen($created['confirmation_token']) >= 40, 'Unlock confirmation token must be cryptographically random');
$createSql = implode("\n", $DB->queries);
assertContainsValue("d.chat_type = 'private'", $createSql, 'AD requests must only use private Telegram chats');
assertContainsValue('d.users_id = 10', $createSql, 'Telegram chat must be bound to the requesting GLPI user');
assertContainsValue('a.can_unlock', $createSql, 'Unlock permission must be checked explicitly');
assertContainsValue('confirmation_token_hash', $createSql, 'Only a token hash must be stored');
assertSameValue(false, str_contains($createSql, $created['confirmation_token']), 'Plain confirmation token must not be persisted in SQL');

$DB->queries = [];
assertSameValue(true, \GlpiPlugin\Telegram\AdRequestService::confirmUnlock(
    $created['request_uuid'],
    $created['confirmation_token'],
    '123456'
), 'A valid confirmation must atomically queue the unlock request');
$confirmSql = implode("\n", $DB->queries);
assertContainsValue("status = 'pending_confirmation'", $confirmSql, 'Confirmation must only consume a pending request');
assertContainsValue("status = 'queued'", $confirmSql, 'Confirmed unlock must enter the execution queue');
assertContainsValue("confirmation_expires_at > NOW()", $confirmSql, 'Expired confirmation must not be accepted');
assertContainsValue("telegram_chat_id = '123456'", $confirmSql, 'Confirmation must originate from the original private chat');

$DB->queries = [];
assertSameValue(false, \GlpiPlugin\Telegram\AdRequestService::confirmUnlock('invalid', 'token', '123456'), 'Malformed confirmation data must be rejected');
assertSameValue(0, count($DB->queries), 'Malformed confirmation must not query the database');

echo "FML Telegram AD request authorization and confirmation tests passed.\n";
