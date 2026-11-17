<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require dirname(__DIR__, 3) . '/inc/includes.php';

// GLPI's plugin autoloader is not guaranteed to be registered for standalone
// CLI entry points. Load the receiver's small, explicit dependency chain.
$pluginSrc = dirname(__DIR__) . '/src/';
foreach ([
    'SecretStore.php',
    'AgentRegistry.php',
    'TelegramClient.php',
    'AdRequestService.php',
    'TelegramUpdateReceiver.php',
] as $classFile) {
    require_once $pluginSrc . $classFile;
}

set_time_limit(0);
\GlpiPlugin\Telegram\TelegramUpdateReceiver::run();
