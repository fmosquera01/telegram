<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/AgentAuth.php';

final class TelegramAgentAuthTestDatabase
{
    public array $queries = [];
    public int $affected = 1;
    public bool $failInsert = false;
    public function escape(string $value): string { return addslashes($value); }
    public function doQuery(string $query): bool
    {
        $this->queries[] = $query;
        return !($this->failInsert && str_contains($query, 'INSERT INTO glpi_plugin_telegram_ad_agent_nonces'));
    }
    public function affectedRows(): int { return $this->affected; }
}

function agentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$path = '/glpi/plugins/telegram/front/ad-agent.php';
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$agentId = 'SVRSNGPRTG';
$body = '{"action":"claim"}';
$signature = \GlpiPlugin\Telegram\AgentAuth::sign($secret, $path, $timestamp, $nonce, $agentId, $body);

agentAssert(\GlpiPlugin\Telegram\AgentAuth::verify($secret, 'POST', $path, $timestamp, $nonce, $agentId, $body, $signature), 'Valid signed request must verify');
agentAssert(!\GlpiPlugin\Telegram\AgentAuth::verify($secret, 'GET', $path, $timestamp, $nonce, $agentId, $body, $signature), 'Only POST is accepted');
agentAssert(!\GlpiPlugin\Telegram\AgentAuth::verify($secret, 'POST', $path . '?action=claim', $timestamp, $nonce, $agentId, $body, $signature), 'Query strings are rejected');
agentAssert(!\GlpiPlugin\Telegram\AgentAuth::verify($secret, 'POST', $path, (string) (time() - 300), $nonce, $agentId, $body, $signature), 'Stale timestamps are rejected');
agentAssert(!\GlpiPlugin\Telegram\AgentAuth::verify($secret, 'POST', $path, $timestamp, $nonce, $agentId, $body . ' ', $signature), 'Body tampering is rejected');
agentAssert(!\GlpiPlugin\Telegram\AgentAuth::verify($secret, 'POST', '/glpi/front/ad-agent.php', $timestamp, $nonce, $agentId, $body, $signature), 'Unexpected endpoint paths are rejected');

$DB = new TelegramAgentAuthTestDatabase();
agentAssert(\GlpiPlugin\Telegram\AgentAuth::consumeNonce($agentId, $nonce), 'Fresh nonce is accepted');
agentAssert(str_contains(implode("\n", $DB->queries), 'INTERVAL 10 MINUTE'), 'Nonce is stored with bounded expiry');
$DB->failInsert = true;
agentAssert(!\GlpiPlugin\Telegram\AgentAuth::consumeNonce($agentId, $nonce), 'Duplicate or failed nonce insert is rejected');

echo "FML Telegram AD agent HMAC contract tests passed.\n";
