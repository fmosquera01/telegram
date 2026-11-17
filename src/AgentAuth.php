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
        return self::verifyDiagnostic(
            $secret,
            $method,
            $requestUri,
            $timestamp,
            $nonce,
            $agentId,
            $body,
            $signature
        )['valid'];
    }

    /**
     * Verify an agent request and return a safe diagnostic reason.
     *
     * The reason contains no secret, signature, nonce, request body or agent
     * credential. It is suitable for the authenticated endpoint's 401 body.
     *
     * @return array{valid:bool,reason:string}
     */
    public static function verifyDiagnostic(
        string $secret,
        string $method,
        string $requestUri,
        string $timestamp,
        string $nonce,
        string $agentId,
        string $body,
        string $signature
    ): array {
        $reject = static function (string $reason): array {
            error_log('[FML Telegram AD HMAC] rejected: ' . $reason);
            return ['valid' => false, 'reason' => $reason];
        };

        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $secret)) {
            return $reject('invalid_server_secret_format');
        }
        if (strtoupper($method) !== 'POST') {
            return $reject('invalid_method');
        }
        if (!preg_match('/^\d{10}$/', $timestamp)) {
            return $reject('invalid_timestamp_format');
        }
        if (abs(time() - (int) $timestamp) > self::CLOCK_SKEW_SECONDS) {
            return $reject('timestamp_out_of_window');
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return $reject('invalid_nonce_format');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $agentId)) {
            return $reject('invalid_agent_id_format');
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return $reject('invalid_signature_format');
        }

        $parts = parse_url($requestUri);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if ($path === '' || isset($parts['query']) || isset($parts['fragment'])
            || !preg_match('~/(?:plugins|marketplace)/telegram/front/ad-agent\.php$~D', $path)
        ) {
            return $reject('invalid_request_path');
        }

        $canonical = "POST\n" . $path . "\n" . $timestamp . "\n" . $nonce
            . "\n" . $agentId . "\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $canonical, $secret);
        if (!hash_equals($expected, strtolower($signature))) {
            return $reject('signature_mismatch');
        }

        return ['valid' => true, 'reason' => 'ok'];
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
