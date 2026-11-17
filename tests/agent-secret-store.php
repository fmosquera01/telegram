<?php

declare(strict_types=1);

/**
 * SecretStore contract tests: per-agent encryption, recovery and safe diagnostics.
 * Run with: php tests/agent-secret-store.php
 */

final class SecretStoreTestDatabase
{
    public array $config = [];
    public array $rows = [];

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function doQuery(string $query): bool
    {
        if (preg_match('/SELECT config_value FROM glpi_plugin_telegram_configs WHERE config_key=\'([^\']+)\'/i', $query, $matches)) {
            $key = stripslashes($matches[1]);
            $this->rows = isset($this->config[$key])
                ? [['config_value' => $this->config[$key]]]
                : [];
            return true;
        }

        if (preg_match('/VALUES \(\'([^\']+)\',\'([^\']+)\'\)/i', $query, $matches)) {
            $this->config[stripslashes($matches[1])] = stripslashes($matches[2]);
            return true;
        }

        return true;
    }

    public function numrows(bool $result): int
    {
        return count($this->rows);
    }

    public function fetchAssoc(bool $result): array|false
    {
        return array_shift($this->rows) ?: false;
    }
}

function secretStoreAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fml-telegram-secret-test-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700, true)) {
    throw new RuntimeException('Unable to create isolated test directory.');
}
define('GLPI_VAR_DIR', $root);

require_once __DIR__ . '/../src/SecretStore.php';

$DB = new SecretStoreTestDatabase();
$agentId = 'TEST-AGENT-01';
$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

\GlpiPlugin\Telegram\SecretStore::saveAgentSecretFor($agentId, $secret);
$recovered = \GlpiPlugin\Telegram\SecretStore::getAgentSecretForDiagnostic($agentId);
secretStoreAssert($recovered['status'] === 'ok', 'A freshly stored agent secret must be recoverable.');
secretStoreAssert(hash_equals($secret, (string) $recovered['secret']), 'Recovered secret must exactly match the registered secret.');

$missing = \GlpiPlugin\Telegram\SecretStore::getAgentSecretForDiagnostic('UNKNOWN-AGENT');
secretStoreAssert($missing['status'] === 'secret_not_found', 'Missing agent secret must have an explicit safe diagnostic.');

$keyPath = $root . '/_plugins/telegram/telegram.key';
unlink($keyPath);
$missingKey = \GlpiPlugin\Telegram\SecretStore::getAgentSecretForDiagnostic($agentId);
secretStoreAssert($missingKey['status'] === 'encryption_key_missing', 'Missing encryption key must not be confused with an HMAC mismatch.');

echo "FML Telegram per-agent secret storage tests passed.\n";
