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
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $secret)) {
            error_log('[FML Telegram AD HMAC] rejected: invalid_server_secret_format');
            return false;
        }
        if (strtoupper($method) !== 'POST') {
            error_log('[FML Telegram AD HMAC] rejected: invalid_method');
            return false;
        }
        if (!preg_match('/^\d{10}$/', $timestamp)) {
            error_log('[FML Telegram AD HMAC] rejected: invalid_timestamp_format');
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::CLOCK_SKEW_SECONDS) {
            error_log('[FML Telegram AD HMAC] rejected: timestamp_out_of_window');
            return false;
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            error_log('[FML Telegram AD HMAC] rejected: invalid_nonce_format');
            return false;
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId)) {
            error_log('[FML Telegram AD HMAC] rejected: invalid_agent_id_format');
            return false;
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            error_log('[FML Telegram AD HMAC] rejected: invalid_signature_format');
            return false;
        }

        $parts = parse_url($requestUri);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if ($path === '' || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('~/(?:plugins|marketplace)/telegram/front/ad-agent\.php$~D', $path)
        ) {
            error_log('[FML Telegram AD HMAC] rejected: invalid_request_path');
            return false;
        }

        $canonical = "POST\n" . $path . "\n" . $timestamp . "\n" . $nonce
            . "\n" . $agentId . "\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, strtolower($signature))) {
            error_log('[FML Telegram AD HMAC] rejected: signature_mismatch');
            return false;
        }

        return true;
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
