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
        if ($name === 'sla') {
            return ['description' => __('Notify about upcoming and overdue ticket SLAs', 'telegram')];
        }

        return [];
    }

    public static function cronSend(?CronTask $task = null): bool
    {
        global $DB;

        try {
            if (SecretStore::getBotToken() === null) {
                // Keep queued messages pending until an administrator configures the bot.
                return true;
            }
        } catch (Throwable $exception) {
            return false;
        }

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
                "SELECT id, chat_id, subject, `message`, parse_mode, attempts,
                        (SELECT COUNT(*) FROM glpi_plugin_telegram_delivery_logs l WHERE l.queue_id = glpi_plugin_telegram_queue.id) AS logged_attempts
                 FROM glpi_plugin_telegram_queue WHERE id = {$id} LIMIT 1"
            );
            $item = $current ? $DB->fetchAssoc($current) : false;
            if (!$item) {
                continue;
            }

            $attemptNumber = (int) $item['logged_attempts'] + 1;
            $cycleAttempt = (int) $item['attempts'] + 1;
            try {
                $subject = trim((string) $item['subject']);
                $body = (string) $item['message'];
                if (($item['parse_mode'] ?? null) === 'HTML') {
                    $outgoingMessage = '<b>' . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        . "</b>\n\n" . $body;
                    $messageId = $client->sendMessage((string) $item['chat_id'], $outgoingMessage, 'HTML');
                } else {
                    $outgoingMessage = $subject . "\n" . $body;
                    $messageId = $client->sendMessage((string) $item['chat_id'], $outgoingMessage);
                }
                $safeMessageId = $DB->escape($messageId);
                $DB->doQuery(
                    "UPDATE glpi_plugin_telegram_queue
                     SET status = 'sent', attempts = {$cycleAttempt}, telegram_message_id = '{$safeMessageId}',
                         date_sent = NOW(), processing_at = NULL, last_error = NULL
                     WHERE id = {$id} AND status = 'processing'"
                );
                self::recordAttempt($id, $attemptNumber, 'sent', $messageId, null);
            } catch (Throwable $exception) {
                $attempts = $cycleAttempt;
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

    /**
     * Scan open tickets in bounded ID batches and enqueue SLA deadline alerts.
     * The cursor prevents large overdue queues from starving newer tickets.
     */
    public static function cronSla(?CronTask $task = null): bool
    {
        global $DB;

        $subscriptions = $DB->doQuery(
            "SELECT COUNT(*) AS total
             FROM glpi_plugin_telegram_subscriptions s
             INNER JOIN glpi_plugin_telegram_destinations d ON d.id = s.destination_id
             WHERE s.`source` = 'glpi'
               AND s.`event` IN ('ticket.sla_due', 'ticket.sla_overdue')
               AND s.is_active = 1 AND d.is_active = 1"
        );
        $subscriptionRow = $subscriptions ? $DB->fetchAssoc($subscriptions) : false;
        if (!$subscriptionRow || (int) $subscriptionRow['total'] === 0) {
            return true;
        }

        $cursorResult = $DB->doQuery(
            "SELECT config_value FROM glpi_plugin_telegram_configs
             WHERE config_key = 'sla_scan_cursor' LIMIT 1"
        );
        $cursorRow = $cursorResult ? $DB->fetchAssoc($cursorResult) : false;
        $cursor = max(0, (int) ($cursorRow['config_value'] ?? 0));

        $tickets = self::fetchSlaBatch($cursor);
        if ($tickets === false) {
            return false;
        }
        if ($tickets === []) {
            $cursor = 0;
            $tickets = self::fetchSlaBatch(0);
            if ($tickets === false) {
                return false;
            }
        }

        $processed = 0;
        $lastId = $cursor;
        foreach ($tickets as $ticket) {
            $ticketId = (int) $ticket['id'];
            $lastId = max($lastId, $ticketId);
            foreach (['time_to_own' => 'atención', 'time_to_resolve' => 'resolución'] as $field => $label) {
                $deadline = trim((string) ($ticket[$field] ?? ''));
                if ($deadline === '' || $deadline === 'NULL' || $deadline === '0000-00-00 00:00:00') {
                    continue;
                }

                $deadlineTimestamp = strtotime($deadline);
                if ($deadlineTimestamp === false || $deadlineTimestamp > time() + 1800) {
                    continue;
                }

                $event = $deadlineTimestamp < time() ? 'ticket.sla_overdue' : 'ticket.sla_due';
                $eventLabel = $event === 'ticket.sla_overdue' ? 'vencido' : 'próximo a vencer';
                $ticketTitle = trim((string) ($ticket['name'] ?? ''));
                $message = "El SLA de {$label} del ticket #{$ticketId} está {$eventLabel}.";
                if ($ticketTitle !== '') {
                    $message .= "\nAsunto: " . $ticketTitle;
                }
                $message .= "\nFecha límite: {$deadline}";

                NotificationService::enqueue([
                    'source' => 'glpi',
                    'event' => $event,
                    'subject' => "SLA {$eventLabel} - ticket #{$ticketId}",
                    'message' => $message,
                    'deduplication_key' => "sla:{$event}:{$ticketId}:{$field}:{$deadline}",
                ]);
            }
            $processed++;
        }

        $nextCursor = count($tickets) < 250 ? 0 : $lastId;
        $saved = $DB->doQuery(
            "INSERT INTO glpi_plugin_telegram_configs (config_key, config_value)
             VALUES ('sla_scan_cursor', '{$nextCursor}')
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
        if ($saved === false) {
            return false;
        }

        if ($task instanceof CronTask) {
            $task->addVolume($processed);
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    private static function fetchSlaBatch(int $cursor): array|false
    {
        global $DB;

        $result = $DB->doQuery(
            "SELECT id, name, time_to_own, time_to_resolve
             FROM glpi_tickets
             WHERE id > {$cursor}
               AND is_deleted = 0
               AND status NOT IN (5, 6)
               AND (
                    (time_to_own IS NOT NULL AND time_to_own NOT IN ('NULL', '0000-00-00 00:00:00')
                     AND time_to_own <= DATE_ADD(NOW(), INTERVAL 30 MINUTE))
                    OR
                    (time_to_resolve IS NOT NULL AND time_to_resolve NOT IN ('NULL', '0000-00-00 00:00:00')
                     AND time_to_resolve <= DATE_ADD(NOW(), INTERVAL 30 MINUTE))
               )
             ORDER BY id ASC LIMIT 250"
        );
        if ($result === false) {
            return false;
        }

        $tickets = [];
        while ($ticket = $DB->fetchAssoc($result)) {
            $tickets[] = $ticket;
        }

        return $tickets;
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
