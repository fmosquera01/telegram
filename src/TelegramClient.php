<?php

namespace GlpiPlugin\Telegram;

use RuntimeException;

final class TelegramClient
{
    public function sendMessage(string $chatId, string $message): string
    {
        $token = SecretStore::getBotToken();
        if ($token === null || $token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $payload = json_encode([
            'chat_id' => $chatId,
            'text' => self::limitMessage($message),
            'disable_web_page_preview' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            throw new RuntimeException('Unable to encode Telegram message.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize Telegram HTTPS request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Telegram transport error: ' . self::sanitizeError($curlError));
        }

        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? '') : '';
            throw new RuntimeException('Telegram API rejected the message: ' . self::sanitizeError($description));
        }

        return (string) ($decoded['result']['message_id'] ?? '');
    }

    private static function limitMessage(string $message): string
    {
        // Telegram's sendMessage text limit is 4096 characters.
        if (mb_strlen($message, 'UTF-8') > 4000) {
            return mb_substr($message, 0, 3990, 'UTF-8') . '…';
        }

        return $message;
    }

    private static function sanitizeError(string $error): string
    {
        $error = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', '[TOKEN]', $error);
        return mb_substr(trim(strip_tags((string) $error)), 0, 350, 'UTF-8');
    }
}
