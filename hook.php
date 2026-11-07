<?php

use GlpiPlugin\Telegram\Installer;
use GlpiPlugin\Telegram\Worker;

/**
 * Install FML Telegram tables and scheduled delivery task.
 */
function plugin_telegram_install()
{
    if (!Installer::install()) {
        return false;
    }

    $task = new CronTask();
    if (!$task->getFromDBbyName(Worker::class, 'send')) {
        if (!CronTask::register(
            Worker::class,
            'send',
            MINUTE_TIMESTAMP,
            [
                'comment' => 'Deliver queued FML Telegram notifications',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        )) {
            return false;
        }
    }

    return true;
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
