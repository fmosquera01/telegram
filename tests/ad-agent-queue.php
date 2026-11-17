<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/AdRequestService.php';

final class TelegramAdAgentQueueTestDatabase
{
    public array $queries = [];
    public array $rows = [];
    public int $affected = 0;
    public array $claimRow = [
        'request_uuid' => '11111111-2222-4333-8444-555555555555',
        'operation' => 'account_status',
        'sam_account_name' => 'usrapp',
        'lease_expires_at' => '2026-11-15 20:00:00',
        'attempts' => 1,
    ];
    public array $completeRow = [
        'id' => 17,
        'operation' => 'account_status',
        'sam_account_name' => 'usrapp',
        'telegram_chat_id' => '123456789',
        'requested_by_users_id' => 10,
    ];
    public function escape(string $value): string { return addslashes($value); }
    public function doQuery(string $query): bool
    {
        $this->queries[] = $query;
        $this->rows = [];
        $trimmed = ltrim($query);
        if (str_starts_with($trimmed, 'SELECT') && str_contains($query, 'SELECT id FROM glpi_plugin_telegram_ad_requests')) {
            $this->rows = [];
        } elseif (str_starts_with($trimmed, 'SELECT') && str_contains($query, 'SELECT request_uuid, operation, sam_account_name')) {
            $this->rows = [$this->claimRow];
        } elseif (str_starts_with($trimmed, 'SELECT') && str_contains($query, 'SELECT id, operation, sam_account_name, telegram_chat_id')) {
            $this->rows = [$this->completeRow];
        } elseif (str_starts_with($trimmed, 'UPDATE glpi_plugin_telegram_ad_requests')) {
            $this->affected = 1;
        }
        return true;
    }
    public function fetchAssoc(bool $result): array|false { return array_shift($this->rows) ?: false; }
    public function affectedRows(): int { return $this->affected; }
}
function queueAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
$DB = new TelegramAdAgentQueueTestDatabase();
$claimed = \GlpiPlugin\Telegram\AdRequestService::claimNext('SVRSNGPRTG');
queueAssert(is_array($claimed), 'Agent must claim a queued request');
queueAssert(strlen($claimed['lease_token']) === 43, 'Claim must return a strong one-time lease token');
$claimSql = implode("\n", $DB->queries);
queueAssert(str_contains($claimSql, "status = 'queued'"), 'Only queued requests may be claimed');
queueAssert(str_contains($claimSql, "target_agent_id = 'SVRSNGPRTG'"), 'Agent can claim only requests assigned to its ID');
queueAssert(str_contains($claimSql, 'INTERVAL 90 SECOND'), 'Claim must have a 90-second lease');
queueAssert(str_contains($claimSql, 'attempts = attempts + 1'), 'Claim must increment attempt count');
$DB->queries = [];
$completed = \GlpiPlugin\Telegram\AdRequestService::complete(
    'SVRSNGPRTG', $claimed['request_uuid'], $claimed['lease_token'], 'ok',
    ['enabled' => true, 'locked_out' => false]
);
queueAssert(is_array($completed) && $completed['status'] === 'completed', 'Valid account status result must complete the lease');
queueAssert($completed['telegram_chat_id'] === '123456789', 'Completion must retain the originating private chat');
$completionSql = implode("\n", $DB->queries);
queueAssert(str_contains($completionSql, "lease_token_hash = '"), 'Completion must validate the lease token hash');
queueAssert(str_contains($completionSql, "status = 'completed'"), 'Successful operation must be marked completed');
queueAssert(str_contains($completionSql, 'habilitada; no bloqueada'), 'Status result must be formatted server-side');
$DB->queries = [];
$invalid = \GlpiPlugin\Telegram\AdRequestService::complete(
    'SVRSNGPRTG', $claimed['request_uuid'], $claimed['lease_token'], 'run_powershell', []
);
queueAssert($invalid === null, 'Arbitrary result codes must be rejected');
$DB->completeRow['operation'] = 'list_locked_accounts';
$DB->queries = [];
$listed = \GlpiPlugin\Telegram\AdRequestService::complete(
    'SVRSNGPRTG', $claimed['request_uuid'], $claimed['lease_token'], 'ok',
    ['accounts' => [
        ['sam_account_name' => 'usrapp', 'display_name' => 'Usuario Aplicacion'],
        ['sam_account_name' => 'usrweb', 'display_name' => 'Usuario Web'],
    ]]
);
queueAssert(is_array($listed) && count($listed['result_data']['accounts']) === 2, 'Locked-account list result must be returned to the Telegram delivery layer');
$listingSql = implode("\n", $DB->queries);
queueAssert(str_contains($listingSql, '"accounts"'), 'The account snapshot must be included in the audit record for pagination');

$DB->completeRow['operation'] = 'unlock_account';
$DB->queries = [];
$disabled = \GlpiPlugin\Telegram\AdRequestService::complete(
    'SVRSNGPRTG', $claimed['request_uuid'], $claimed['lease_token'], 'account_disabled', [
        'enabled_before' => false, 'locked_out_before' => true,
        'enabled_after' => false, 'locked_out_after' => true,
    ]
);
queueAssert(is_array($disabled) && $disabled['status'] === 'failed', 'A disabled AD account must be reported as failed without being treated as unlocked');
queueAssert(str_contains($disabled['result_message'], 'No se realizó ningún cambio'), 'Disabled account result must explicitly confirm no change');

echo "FML Telegram AD agent queue contract tests passed.\n";
