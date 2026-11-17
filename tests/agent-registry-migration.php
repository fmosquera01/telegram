<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/AgentRegistry.php';

final class AgentRegistryMigrationTestDatabase
{
    public array $config = ['ad_agent_hmac_secret' => 'legacy-encrypted-secret'];
    public array $agents = [];
    public array $rows = [];
    public array $queries = [];
    public bool $failLegacyDelete = false;

    public function escape(string $value): string
    {
        return addslashes($value);
    }

    public function doQuery(string $query): bool
    {
        $this->queries[] = $query;

        if (str_contains($query, "SELECT config_value FROM glpi_plugin_telegram_configs")
            && str_contains($query, "config_key = 'ad_agent_hmac_secret'")) {
            $this->rows = isset($this->config['ad_agent_hmac_secret'])
                ? [['config_value' => $this->config['ad_agent_hmac_secret']]]
                : [];
            return true;
        }

        if (preg_match("/INSERT INTO glpi_plugin_telegram_configs \\(config_key,config_value\\) VALUES \\('([^']+)','([^']*)'\\)/", $query, $m)) {
            $this->config[stripslashes($m[1])] = stripslashes($m[2]);
            return true;
        }

        if (str_contains($query, 'INSERT INTO glpi_plugin_telegram_ad_agents')) {
            $this->agents['SVRSNGPRTG-LAB'] = true;
            return true;
        }

        if (str_contains($query, "SELECT id FROM glpi_plugin_telegram_configs")
            && str_contains($query, "config_key='ad_default_agent_id'")) {
            $this->rows = [];
            return true;
        }

        if (str_contains($query, "DELETE FROM glpi_plugin_telegram_configs WHERE config_key = 'ad_agent_hmac_secret'")) {
            if ($this->failLegacyDelete) {
                return false;
            }
            unset($this->config['ad_agent_hmac_secret']);
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

function registryMigrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$DB = new AgentRegistryMigrationTestDatabase();
registryMigrationAssert(\GlpiPlugin\Telegram\\AgentRegistry::migrateLegacyAgent(), 'Legacy migration should complete.');
registryMigrationAssert(isset($DB->config['ad_agent_hmac_' . hash('sha256', 'SVRSNGPRTG-LAB')]), 'Legacy ciphertext must be copied to the per-agent key.');
registryMigrationAssert(!isset($DB->config['ad_agent_hmac_secret']), 'Legacy shared secret must be retired after migration.');
registryMigrationAssert(isset($DB->agents['SVRSNGPRTG-LAB']), 'Legacy agent record must be migrated.');

$insertCount = count(array_filter($DB->queries, static fn ($q) => str_contains($q, 'INSERT INTO glpi_plugin_telegram_ad_agents')));
registryMigrationAssert(\GlpiPlugin\Telegram\\AgentRegistry::migrateLegacyAgent(), 'A second migration run should be harmless.');
registryMigrationAssert($insertCount === count(array_filter($DB->queries, static fn ($q) => str_contains($q, 'INSERT INTO glpi_plugin_telegram_ad_agents'))), 'A retired legacy secret must not recreate the old agent.');

$DB = new AgentRegistryMigrationTestDatabase();
$DB->failLegacyDelete = true;
registryMigrationAssert(!\GlpiPlugin\Telegram\\AgentRegistry::migrateLegacyAgent(), 'Migration must report failure if legacy secret retirement fails.');
registryMigrationAssert(isset($DB->config['ad_agent_hmac_secret']), 'Legacy secret must remain available if retirement fails.');

echo "FML Telegram legacy AD agent migration tests passed.\n";
