<?php

namespace GlpiPlugin\Telegram;

final class AgentAuth
{
    private const CLOCK_SKEW_SECONDS = 60;

    public static function verify(
        string $secret,
        string $method,
        string $requestUri,
        string $timestamp,
        string $nonce,
        string $agentId,
        string $body,
        string $signature
    ): bool {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $secret)
            || strtoupper($method) !== 'POST'
            || !preg_match('/^\d{10}$/', $timestamp)
            || abs(time() - (int) $timestamp) > self::CLOCK_SKEW_SECONDS
            || !preg_match('/^[a-f0-9]{32}$/', $nonce)
            || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId)
            || !preg_match('/^[a-f0-9]{64}$/i', $signature)
        ) {
            return false;
        }

        $parts = parse_url($requestUri);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if ($path === '' || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('~/(?:plugins|marketplace)/telegram/front/ad-agent\.php$~D', $path)
        ) {
            return false;
        }

        $canonical = "POST\n" . $path . "\n" . $timestamp . "\n" . $nonce
            . "\n" . $agentId . "\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $canonical, $secret);

        return hash_equals($expected, strtolower($signature));
    }

    public static function consumeNonce(string $agentId, string $nonce): bool
    {
        global $DB;

        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId)
            || !preg_match('/^[a-f0-9]{32}$/', $nonce)
        ) {
            return false;
        }

        $DB->doQuery('DELETE FROM glpi_plugin_telegram_ad_agent_nonces WHERE expires_at < NOW()');
        $safeAgent = $DB->escape($agentId);
        $safeNonce = $DB->escape($nonce);
        $insert = $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_ad_agent_nonces (agent_id, nonce, expires_at)
             VALUES ('{$safeAgent}', '{$safeNonce}', DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
        );

        return $insert !== false && $DB->affectedRows() === 1;
    }

    public static function sign(
        string $secret,
        string $path,
        string $timestamp,
        string $nonce,
        string $agentId,
        string $body
    ): string {
        $canonical = "POST\n" . $path . "\n" . $timestamp . "\n" . $nonce
            . "\n" . $agentId . "\n" . hash('sha256', $body);
        return hash_hmac('sha256', $canonical, $secret);
    }
}
