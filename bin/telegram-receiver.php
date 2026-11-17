<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// Standalone GLPI CLI scripts must boot the Kernel to initialize GLPI_ROOT,
// GLPI_CONFIG_DIR, GLPI_VAR_DIR and the plugin environment.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

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
