<?php

namespace GlpiPlugin\Telegram;

use CronTask;
use Throwable;

final class Worker
{
    private const BATCH_SIZE = 20;
    private const MAX_ATTEMPTS = 8;

    public static function cronInfo(string $name): array
    {
        if ($name === 'send') {
            return ['description' => __('Deliver queued Telegram notifications', 'telegram')];
        }

        return [];
    }

    public static function cronSend(?CronTask $task = null): bool
    {
        global $DB;

        self::recoverStaleItems();

        $result = $DB->doQuery(
            "SELECT id FROM glpi_plugin_telegram_queue
             WHERE status IN ('pending', 'retry')
               AND next_attempt_at <= NOW()
             ORDER BY id ASC LIMIT " . self::BATCH_SIZE
        );

        if (!$result) {
            return false;
        }

        $client = new TelegramClient();
        $processed = 0;

        while ($row = $DB->fetchAssoc($result)) {
            $id = (int) $row['id'];
            $claim = $DB->doQuery(
                "UPDATE glpi_plugin_telegram_queue
                 SET status = 'processing', processing_at = NOW()
                 WHERE id = {$id} AND status IN ('pending', 'retry')
                   AND next_attempt_at <= NOW()"
            );

            if (!$claim || $DB->affectedRows() !== 1) {
                continue;
            }

            $current = $DB->doQuery(
                "SELECT id, chat_id, subject, `message`, attempts
                 FROM glpi_plugin_telegram_queue WHERE id = {$id} LIMIT 1"
            );
            $item = $current ? $DB->fetchAssoc($current) : false;
            if (!$item) {
                continue;
            }

            $attemptNumber = (int) $item['attempts'] + 1;
            try {
                $outgoingMessage = trim((string) $item['subject']) . "\n" . (string) $item['message'];
                $messageId = $client->sendMessage((string) $item['chat_id'], $outgoingMessage);
                $safeMessageId = $DB->escape($messageId);
                $DB->doQuery(
                    "UPDATE glpi_plugin_telegram_queue
                     SET status = 'sent', telegram_message_id = '{$safeMessageId}',
                         date_sent = NOW(), processing_at = NULL, last_error = NULL
                     WHERE id = {$id} AND status = 'processing'"
                );
                self::recordAttempt($id, $attemptNumber, 'sent', $messageId, null);
            } catch (Throwable $exception) {
                $attempts = $attemptNumber;
                $safeError = self::sanitizeError($exception->getMessage());
                $error = $DB->escape($safeError);
                if ($attempts >= self::MAX_ATTEMPTS) {
                    $DB->doQuery(
                        "UPDATE glpi_plugin_telegram_queue
                         SET status = 'failed', attempts = {$attempts},
                             processing_at = NULL, last_error = '{$error}'
                         WHERE id = {$id} AND status = 'processing'"
                    );
                    self::recordAttempt($id, $attempts, 'failed', null, $safeError);
                } else {
                    $delay = min(3600, 30 * (2 ** ($attempts - 1)));
                    $DB->doQuery(
                        "UPDATE glpi_plugin_telegram_queue
                         SET status = 'retry', attempts = {$attempts},
                             next_attempt_at = DATE_ADD(NOW(), INTERVAL {$delay} SECOND),
                             processing_at = NULL, last_error = '{$error}'
                         WHERE id = {$id} AND status = 'processing'"
                    );
                    self::recordAttempt($id, $attempts, 'retry', null, $safeError);
                }
            }

            $processed++;
        }

        if ($task instanceof CronTask) {
            $task->addVolume($processed);
        }

        return true;
    }

    private static function recordAttempt(
        int $queueId,
        int $attemptNumber,
        string $status,
        ?string $messageId,
        ?string $error
    ): void {
        global $DB;

        try {
            $messageIdSql = $messageId === null || $messageId === ''
                ? 'NULL'
                : "'" . $DB->escape($messageId) . "'";
            $errorSql = $error === null || $error === ''
                ? 'NULL'
                : "'" . $DB->escape($error) . "'";

            $DB->doQuery(
                "INSERT INTO glpi_plugin_telegram_delivery_logs
                    (queue_id, attempt_number, result_status, telegram_message_id, error_message)
                 VALUES ({$queueId}, {$attemptNumber}, '" . $DB->escape($status) . "',
                         {$messageIdSql}, {$errorSql})"
            );
        } catch (Throwable $exception) {
            // Audit failure must not cause a successfully delivered message to be resent.
        }
    }

    private static function recoverStaleItems(): void
    {
        global $DB;

        $DB->doQuery(
            "UPDATE glpi_plugin_telegram_queue
             SET status = 'retry', processing_at = NULL, next_attempt_at = NOW(),
                 last_error = 'Recovered after an interrupted delivery process'
             WHERE status = 'processing'
               AND processing_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
    }

    private static function sanitizeError(string $error): string
    {
        $error = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', '[TOKEN]', $error);
        return mb_substr(trim(strip_tags((string) $error)), 0, 450, 'UTF-8');
    }
}
