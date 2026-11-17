<?php
require dirname(__DIR__, 2) . '/inc/includes.php';

require_once __DIR__ . '/src/SecretStore.php';
require_once __DIR__ . '/src/AgentRegistry.php';

$id = 'SVRSNGPRTG-LAB';

echo "=== DIAGNOSTICO DE AUTENTICACION ===\n";

$r = \GlpiPlugin\Telegram\AgentRegistry::getApprovedSecretDiagnostic($id);

echo "AgentId: {$id}\n";
echo "Resultado: {$r['status']}\n";
echo "Secreto recuperable: " . ($r['secret'] !== null ? 'SI' : 'NO') . "\n";

if ($r['secret'] !== null) {
    echo "Longitud: " . strlen($r['secret']) . "\n";
    echo "Huella SHA256: " . hash('sha256', $r['secret']) . "\n";
}

echo "\n=== AGENTE PREDETERMINADO ===\n";
echo "AgentId: " . (\GlpiPlugin\Telegram\AgentRegistry::getDefaultAgentId(false) ?? 'NINGUNO') . "\n";
