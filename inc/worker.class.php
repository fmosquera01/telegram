<?php

/**
 * Legacy GLPI cron entry point for FML Telegram.
 *
 * GLPI's external cron selector handles legacy plugin itemtypes
 * (Plugin<plugin-key>...) consistently. Keep the implementation in the
 * namespaced service and expose only the cron entry points here.
 */

use GlpiPlugin\Telegram\Worker;

class PluginTelegramWorker extends CommonDBTM
{
    public static function cronInfo($name)
    {
        return Worker::cronInfo((string) $name);
    }

    public static function cronSend(?CronTask $task = null): bool
    {
        return Worker::cronSend($task);
    }

    public static function cronSla(?CronTask $task = null): bool
    {
        return Worker::cronSla($task);
    }
}
