<?php

use GlpiPlugin\Telegram\AdRequestService;
use GlpiPlugin\Telegram\AgentAuth;
use GlpiPlugin\Telegram\SecretStore;
use GlpiPlugin\Telegram\NotificationService;
use GlpiPlugin\Telegram\TelegramClient;

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['success' => false, 'error' => 'method_not_allowed']);
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    $respond(415, ['success' => false, 'error' => 'application_json_required']);
}
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 8192) {
    $respond(413, ['success' => false, 'error' => 'request_too_large']);
}
$body = file_get_contents('php://input', false, null, 0, 8193);
if ($body === false || strlen($body) > 8192) {
    $respond(413, ['success' => false, 'error' => 'request_too_large']);
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$getHeader = static function (string $name) use ($headers): string {
    foreach ($headers as $key => $value) {
        if (strcasecmp((string) $key, $name) === 0) {
            return trim((string) $value);
        }
    }
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$serverKey] ?? ''));
};

try {
    $agentId = $getHeader('X-FML-Agent');
    $timestamp = $getHeader('X-FML-Timestamp');
    $nonce = $getHeader('X-FML-Nonce');
    $signature = $getHeader('X-FML-Signature');
    $secret = SecretStore::getAgentSecret();

    if ($secret === null || !AgentAuth::verify(
        $secret,
        'POST',
        (string) ($_SERVER['REQUEST_URI'] ?? ''),
        $timestamp,
        $nonce,
        $agentId,
        $body,
        $signature
    )) {
        $respond(401, ['success' => false, 'error' => 'unauthorized']);
    }
    if (!AgentAuth::consumeNonce($agentId, $nonce)) {
        $respond(409, ['success' => false, 'error' => 'replayed_request']);
    }

    $payload = json_decode($body, true);
    if (!is_array($payload) || !is_string($payload['action'] ?? null)) {
        $respond(400, ['success' => false, 'error' => 'invalid_json']);
    }

    if ($payload['action'] === 'claim') {
        $request = AdRequestService::claimNext($agentId);
        $respond(200, ['success' => true, 'request' => $request]);
    }

    if ($payload['action'] === 'complete') {
        $requestUuid = (string) ($payload['request_uuid'] ?? '');
        $leaseToken = (string) ($payload['lease_token'] ?? '');
        $resultCode = (string) ($payload['result_code'] ?? '');
        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            $respond(400, ['success' => false, 'error' => 'invalid_result_data']);
        }

        $completed = AdRequestService::complete(
            $agentId,
            $requestUuid,
            $leaseToken,
            $resultCode,
            $data
        );
        if ($completed === null) {
            $respond(409, ['success' => false, 'error' => 'request_not_leased_or_result_invalid']);
        }

        try {
            (new TelegramClient())->sendMessage(
                $completed['telegram_chat_id'],
                $completed['result_message']
            );
        } catch (Throwable $deliveryError) {
            $fallback = NotificationService::enqueue([
                'source' => 'telegram',
                'event' => 'ad.result_fallback',
                'subject' => 'Resultado de Active Directory',
                'message' => $completed['result_message'],
                'recipients' => ['user:' . (int) $completed['requested_by_users_id']],
                'deduplication_key' => 'ad-result:' . $completed['request_uuid'],
            ]);
            if (($fallback['queued'] ?? 0) < 1) {
                error_log('[FML Telegram AD] Direct and queued result delivery failed for request ' . $completed['request_uuid']);
            }
        }

        $respond(200, [
            'success' => true,
            'request_uuid' => $completed['request_uuid'],
            'status' => $completed['status'],
            'result_code' => $completed['result_code'],
        ]);
    }

    $respond(400, ['success' => false, 'error' => 'unsupported_action']);
} catch (Throwable $exception) {
    error_log('[FML Telegram AD API] ' . mb_substr(strip_tags($exception->getMessage()), 0, 250));
    $respond(500, ['success' => false, 'error' => 'internal_error']);
}
