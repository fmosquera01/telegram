<?php

use GlpiPlugin\Telegram\Installer;
use GlpiPlugin\Telegram\Worker;

/**
 * Install FML Telegram tables, queue delivery and SLA alert tasks.
 */
function plugin_telegram_install()
{
    if (!Installer::install()) {
        return false;
    }

    $tasks = [
        'send' => [
            'frequency' => MINUTE_TIMESTAMP,
            'comment' => 'Deliver queued FML Telegram notifications',
        ],
        'sla' => [
            'frequency' => 5 * MINUTE_TIMESTAMP,
            'comment' => 'Check upcoming and overdue FML Telegram ticket SLAs',
        ],
    ];

    foreach ($tasks as $name => $options) {
        $task = new CronTask();

        // Migrate existing namespaced task records in place. Keeping their IDs
        // preserves the execution history and avoids duplicate cron entries.
        if ($task->getFromDBbyName(Worker::class, $name)) {
            if (!$task->update([
                'id'        => $task->getID(),
                'itemtype'  => PluginTelegramWorker::class,
                'frequency' => $options['frequency'],
                'mode'      => CronTask::MODE_EXTERNAL,
                'comment'   => $options['comment'],
            ])) {
                return false;
            }
            continue;
        }

        // Do not create duplicates if the task is already registered.
        if ($task->getFromDBbyName(PluginTelegramWorker::class, $name)) {
            continue;
        }

        if (!CronTask::register(
            PluginTelegramWorker::class,
            $name,
            $options['frequency'],
            [
                'comment' => $options['comment'],
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        )) {
            return false;
        }
    }

    return true;
}

/**
 * Migrate existing installations to the GLPI-compatible cron itemtype.
 */
function plugin_telegram_upgrade($oldVersion)
{
    return plugin_telegram_install();
}

/**
 * Keep plugin data on uninstall. The administrator can explicitly remove
 * retained data later; uninstall must not silently destroy delivery history.
 */
function plugin_telegram_uninstall()
{
    CronTask::unregister('telegram');
    return true;
}
