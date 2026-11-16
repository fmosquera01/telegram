<?php

namespace GlpiPlugin\Telegram;

use RuntimeException;

final class SecretStore
{
    private const KEY_FILENAME = 'telegram.key';
    private const CONFIG_KEY = 'bot_token';
    private const AGENT_CONFIG_KEY = 'ad_agent_hmac_secret';

    public static function saveBotToken(string $token): void
    {
        global $DB;

        $token = trim($token);
        if ($token === '' || strlen($token) > 256) {
            throw new RuntimeException('The Telegram bot token is empty or invalid.');
        }

        $key = self::getOrCreateKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($token, $nonce, $key);
        $stored = base64_encode($nonce . $ciphertext);
        $escaped = $DB->escape($stored);

        if ($DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_configs (config_key, config_value)
             VALUES ('" . self::CONFIG_KEY . "', '" . $escaped . "')
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        ) === false) {
            throw new RuntimeException('Unable to store the encrypted Telegram bot token.');
        }
    }

    public static function getBotToken(): ?string
    {
        global $DB;

        $result = $DB->doQuery(
            "SELECT config_value FROM glpi_plugin_telegram_configs
             WHERE config_key = '" . self::CONFIG_KEY . "' LIMIT 1"
        );
        if (!$result || $DB->numrows($result) !== 1) {
            return null;
        }

        $row = $DB->fetchAssoc($result);
        $decoded = base64_decode((string) $row['config_value'], true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, self::getExistingKey());

        return $plain === false ? null : $plain;
    }

    public static function generateAgentSecret(): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::saveAgentSecret($secret);
        return $secret;
    }

    public static function saveAgentSecret(string $secret): void
    {
        global $DB;
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $secret)) {
            throw new RuntimeException('The AD agent secret must be a 256-bit base64url value.');
        }
        $key = self::getOrCreateKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $key);
        $stored = base64_encode($nonce . $ciphertext);
        $escaped = $DB->escape($stored);
        if ($DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_configs (config_key, config_value)
             VALUES ('" . self::AGENT_CONFIG_KEY . "', '" . $escaped . "')
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        ) === false) {
            throw new RuntimeException('Unable to store the encrypted AD agent secret.');
        }
    }

    public static function getAgentSecret(): ?string
    {
        global $DB;
        $result = $DB->doQuery(
            "SELECT config_value FROM glpi_plugin_telegram_configs
             WHERE config_key = '" . self::AGENT_CONFIG_KEY . "' LIMIT 1"
        );
        if (!$result || $DB->numrows($result) !== 1) {
            return null;
        }
        $row = $DB->fetchAssoc($result);
        $decoded = base64_decode((string) $row['config_value'], true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, self::getExistingKey());
        return $plain !== false && preg_match('/^[A-Za-z0-9_-]{43}$/', $plain) ? $plain : null;
    }

    public static function generateAgentSecretFor(string $agentId): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::saveAgentSecretFor($agentId, $secret);
        return $secret;
    }

    public static function saveAgentSecretFor(string $agentId, string $secret): void
    {
        global $DB;
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $secret)) {
            throw new RuntimeException('Identificador o secreto HMAC no válido.');
        }
        $key = self::getOrCreateKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($secret, $nonce, $key);
        $stored = base64_encode($nonce . $ciphertext);
        $configKey = $DB->escape('ad_agent_hmac_' . hash('sha256', $agentId));
        $escaped = $DB->escape($stored);
        if ($DB->doQuery("INSERT INTO glpi_plugin_telegram_configs (config_key,config_value) VALUES ('{$configKey}','{$escaped}') ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)") === false) {
            throw new RuntimeException('No se pudo guardar el secreto cifrado del agente.');
        }
    }

    public static function getAgentSecretFor(string $agentId): ?string
    {
        global $DB;
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId)) return null;
        $configKey = $DB->escape('ad_agent_hmac_' . hash('sha256', $agentId));
        $q = $DB->doQuery("SELECT config_value FROM glpi_plugin_telegram_configs WHERE config_key='{$configKey}' LIMIT 1");
        if (!$q || $DB->numrows($q) !== 1) return null;
        $row = $DB->fetchAssoc($q);
        $decoded = base64_decode((string)$row['config_value'], true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, self::getExistingKey());
        return $plain !== false && preg_match('/^[A-Za-z0-9_-]{43}$/', $plain) ? $plain : null;
    }

    private static function getOrCreateKey(): string
    {
        $path = self::getKeyPath();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the private FML Telegram key directory.');
        }
        @chmod($directory, 0700);

        if (is_link($path)) {
            throw new RuntimeException('FML Telegram encryption key cannot be a symbolic link.');
        }

        // Lock the key file so simultaneous token saves cannot create competing keys.
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the FML Telegram encryption key file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the FML Telegram encryption key file.');
            }

            $stats = fstat($handle);
            if ($stats === false || !isset($stats['size'])) {
                throw new RuntimeException('Unable to inspect the FML Telegram encryption key file.');
            }

            if ((int) $stats['size'] === 0) {
                $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
                if (!ftruncate($handle, 0) || !rewind($handle)
                    || fwrite($handle, $key) !== strlen($key) || !fflush($handle)
                ) {
                    throw new RuntimeException('Unable to persist the FML Telegram encryption key.');
                }
            } else {
                rewind($handle);
                $key = stream_get_contents($handle);
                if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                    throw new RuntimeException('FML Telegram encryption key is invalid.');
                }
            }

            @chmod($path, 0600);

            return $key;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function getExistingKey(): string
    {
        $path = self::getKeyPath();
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('FML Telegram encryption key is missing; token cannot be decrypted.');
        }

        @chmod(dirname($path), 0700);
        @chmod($path, 0600);

        return self::readKey($path);
    }

    private static function readKey(string $path): string
    {
        if (is_link($path)) {
            throw new RuntimeException('FML Telegram encryption key cannot be a symbolic link.');
        }
        $key = file_get_contents($path);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('FML Telegram encryption key is invalid.');
        }

        return $key;
    }

    private static function getKeyPath(): string
    {
        if (!defined('GLPI_VAR_DIR')) {
            throw new RuntimeException('GLPI_VAR_DIR is not available.');
        }

        return GLPI_VAR_DIR . '/_plugins/telegram/' . self::KEY_FILENAME;
    }
}
