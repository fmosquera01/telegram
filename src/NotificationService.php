<?php

namespace GlpiPlugin\Telegram;

use InvalidArgumentException;
use Throwable;

final class NotificationService
{
    public const API_VERSION = '1.0';

    public static function getApiVersion(): string
    {
        return self::API_VERSION;
    }

    /**
     * Persist a notification for its configured destinations.
     *
     * @param array{
     *   source:string,
     *   event:string,
     *   subject:string,
     *   message:string,
     *   recipients?:array<int,string>,
     *   deduplication_key?:string|null
     * } $notification
     *
     * @return array{queued:int, skipped:int, errors:array<int,string>}
     */
    public static function enqueue(array $notification): array
    {
        try {
            return self::enqueueInternal($notification);
        } catch (Throwable $exception) {
            // A notification failure must never interrupt the GLPI business operation.
            if (class_exists('Toolbox')) {
                Toolbox::logError('FML Telegram queue persistence failed', get_class($exception));
            }
            return [
                'queued' => 0,
                'skipped' => 0,
                'errors' => ['FML Telegram could not persist the notification.'],
            ];
        }
    }

    private static function enqueueInternal(array $notification): array
    {
        global $DB;

        try {
            self::validate($notification);
        } catch (Throwable $exception) {
            return ['queued' => 0, 'skipped' => 0, 'errors' => [$exception->getMessage()]];
        }

        $source = $DB->escape($notification['source']);
        $event = $DB->escape($notification['event']);
        $subject = $DB->escape($notification['subject']);
        $message = $DB->escape($notification['message']);
        $dedup = isset($notification['deduplication_key']) && $notification['deduplication_key'] !== ''
            ? $DB->escape($notification['deduplication_key'])
            : null;

        $destinations = self::resolveDestinations(
            $source,
            $event,
            $notification['recipients'] ?? []
        );

        $queued = 0;
        $skipped = 0;
        $errors = [];

        foreach ($destinations as $destination) {
            $destinationId = (int) $destination['id'];
            $chatId = $DB->escape((string) $destination['chat_id']);
            $dedupSql = $dedup === null ? 'NULL' : "'" . $dedup . "'";

            $query = "INSERT IGNORE INTO glpi_plugin_telegram_queue
                (`source`, `event`, subject, `message`, destination_id, chat_id,
                 status, attempts, next_attempt_at, deduplication_key)
                VALUES (
                    '{$source}', '{$event}', '{$subject}', '{$message}',
                    {$destinationId}, '{$chatId}', 'pending', 0, NOW(), {$dedupSql}
                )";

            if ($DB->doQuery($query)) {
                if ($DB->affectedRows() > 0) {
                    $queued++;
                } else {
                    $skipped++;
                }
            } else {
                $errors[] = 'Unable to queue notification for destination ' . $destinationId;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'errors' => $errors];
    }

    private static function validate(array $data): void
    {
        foreach (['source', 'event', 'subject', 'message'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
                throw new InvalidArgumentException('Missing or invalid notification field: ' . $field);
            }
        }

        if (strlen($data['source']) > 80 || strlen($data['event']) > 120
            || mb_strlen($data['subject'], 'UTF-8') > 180
            || mb_strlen($data['message'], 'UTF-8') > 20000
            || strlen($data['message']) > 60000
        ) {
            throw new InvalidArgumentException('Notification exceeds the allowed field length.');
        }

        if (isset($data['recipients'])) {
            if (!is_array($data['recipients'])) {
                throw new InvalidArgumentException('Recipients must be an array of destination aliases or user:<id> values.');
            }
            foreach ($data['recipients'] as $recipient) {
                if (!is_string($recipient) || trim($recipient) === '' || strlen($recipient) > 130) {
                    throw new InvalidArgumentException('Each recipient must be a non-empty alias or user:<id> value.');
                }
            }
        }

        if (isset($data['deduplication_key']) && strlen((string) $data['deduplication_key']) > 191) {
            throw new InvalidArgumentException('Deduplication key is too long.');
        }
    }

    private static function resolveDestinations(string $source, string $event, array $recipients): array
    {
        global $DB;

        if ($recipients !== []) {
            $aliases = [];
            $userIds = [];

            foreach ($recipients as $recipient) {
                $recipient = trim((string) $recipient);
                if (preg_match('/^user:(\\d+)$/', $recipient, $matches)) {
                    $userIds[] = (int) $matches[1];
                } elseif ($recipient !== '') {
                    $aliases[] = $recipient;
                }
            }

            $conditions = [];
            if ($aliases !== []) {
                $quoted = array_map(static fn ($alias) => "'" . $DB->escape($alias) . "'", array_unique($aliases));
                $conditions[] = 'd.alias IN (' . implode(',', $quoted) . ')';
            }
            if ($userIds !== []) {
                $conditions[] = 'd.users_id IN (' . implode(',', array_unique($userIds)) . ')';
            }
            if ($conditions === []) {
                return [];
            }

            $where = '(' . implode(' OR ', $conditions) . ') AND d.is_active = 1';
        } else {
            $where = "s.`source` = '{$source}' AND s.`event` = '{$event}'
                AND s.is_active = 1 AND d.is_active = 1";
        }

        $query = "SELECT DISTINCT d.id, d.chat_id
            FROM glpi_plugin_telegram_destinations d
            " . ($recipients === [] ? 'INNER JOIN glpi_plugin_telegram_subscriptions s ON s.destination_id = d.id' : '') . "
            WHERE {$where}";

        $result = $DB->doQuery($query);
        $destinations = [];
        if ($result) {
            while ($row = $DB->fetchAssoc($result)) {
                $destinations[] = $row;
            }
        }

        return $destinations;
    }
}
