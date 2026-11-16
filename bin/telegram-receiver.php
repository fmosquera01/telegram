<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__, 3) . '/inc/includes.php';

use GlpiPlugin\Telegram\TelegramUpdateReceiver;

set_time_limit(0);
TelegramUpdateReceiver::run();
