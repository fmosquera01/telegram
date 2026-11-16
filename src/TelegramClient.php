<?php

namespace GlpiPlugin\Telegram;

use RuntimeException;

final class TelegramClient
{
    public function sendMessage(string $chatId, string $message, ?string $parseMode = null, ?array $replyMarkup = null): string
    {
        if ($parseMode !== null && $parseMode !== 'HTML') {
            throw new RuntimeException('Unsupported Telegram message parse mode.');
        }

        if ($parseMode === 'HTML') {
            try {
                return $this->sendMessageRequest($chatId, $message, 'HTML', $replyMarkup);
            } catch (RuntimeException $exception) {
                if (!self::isFormattingError($exception->getMessage())) {
                    throw $exception;
                }
                return $this->sendMessageRequest($chatId, self::htmlToPlainText($message), null, $replyMarkup);
            }
        }

        return $this->sendMessageRequest($chatId, $message, null, $replyMarkup);
    }

    /** Convert Telegram-compatible HTML into readable plain text for fallback. */
    public static function htmlToPlainText(string $html): string
    {
        $text = preg_replace('/<\\s*br\\s*\\/?\\s*>/i', "\n", $html);
        $text = preg_replace('/<\\s*\\/(?:p|div|li|h[1-6]|blockquote|pre)\\s*>/i', "\n", (string) $text);
        $text = preg_replace('/<\\s*li(?:\\s[^>]*)?>/i', "• ", (string) $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\\n[\\t ]*\\n[\\t ]*\\n+/u", "\n\n", $text);
        return trim((string) $text);
    }

    private static function isFormattingError(string $error): bool
    {
        return (bool) preg_match(
            '/parse entities|unsupported start tag|unsupported end tag|can.t find end of|message is too long|message text is too long/i',
            $error
        );
    }

    private function sendMessageRequest(string $chatId, string $message, ?string $parseMode = null, ?array $replyMarkup = null): string
    {
        $token = SecretStore::getBotToken();
        if ($token === null || $token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $parameters = [
            'chat_id' => $chatId,
            'text' => self::limitMessage($message),
            'disable_web_page_preview' => true,
        ];
        if ($parseMode !== null) {
            $parameters['parse_mode'] = $parseMode;
        }
        if ($replyMarkup !== null) {
            $parameters['reply_markup'] = $replyMarkup;
        }
        $payload = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

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

        $messageId = $decoded['result']['message_id'] ?? null;
        if (!is_int($messageId) && !is_string($messageId)) {
            throw new RuntimeException('Telegram response did not include a valid message ID.');
        }

        return (string) $messageId;
    }

    /**
     * Telegram Long Polling. The caller owns the persistent loop and offset.
     * Telegram permits up to 50 seconds; this implementation caps it at 25
     * so service shutdown and recovery remain responsive.
     */
    public function getUpdates(int $offset = 0, int $timeout = 25): array
    {
        $token = SecretStore::getBotToken();
        if ($token === null || $token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $timeout = max(0, min(25, $timeout));
        $payload = json_encode([
            'offset' => max(0, $offset),
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new RuntimeException('Unable to encode Telegram polling request.');
        }

        $curl = curl_init('https://api.telegram.org/bot' . $token . '/getUpdates');
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize Telegram Long Polling request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout + 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Telegram Long Polling transport error: ' . self::sanitizeError($curlError));
        }
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? '') : '';
            throw new RuntimeException('Telegram Long Polling failed: ' . self::sanitizeError($description));
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void
    {
        if ($callbackQueryId === '' || strlen($callbackQueryId) > 256) {
            throw new RuntimeException('Invalid Telegram callback query ID.');
        }
        $token = SecretStore::getBotToken();
        if ($token === null || $token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $payload = json_encode([
            'callback_query_id' => $callbackQueryId,
            'text' => mb_substr($text, 0, 180, 'UTF-8'),
            'show_alert' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new RuntimeException('Unable to encode Telegram callback response.');
        }

        $curl = curl_init('https://api.telegram.org/bot' . $token . '/answerCallbackQuery');
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize Telegram callback response.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException('Telegram callback transport error: ' . self::sanitizeError($curlError));
        }
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? '') : '';
            throw new RuntimeException('Telegram callback response failed: ' . self::sanitizeError($description));
        }
    }

    public function getMe(): array
    {
        $token = SecretStore::getBotToken();
        if ($token === null || $token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $curl = curl_init('https://api.telegram.org/bot' . $token . '/getMe');
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize Telegram HTTPS request.');
        }

        curl_setopt_array($curl, [
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
            throw new RuntimeException('Telegram API rejected the request: ' . self::sanitizeError($description));
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
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
