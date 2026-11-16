$ErrorActionPreference = 'Stop'
$Utf8 = [System.Text.UTF8Encoding]::new($false)
$secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopq'
$path = '/glpi/plugins/telegram/front/ad-agent.php'
$timestamp = '1794700000'
$nonce = '00112233445566778899aabbccddeeff'
$agentId = 'SVRSNGPRTG'
$body = '{"action":"claim"}'
$bodyHash = ([BitConverter]::ToString(([System.Security.Cryptography.SHA256]::Create()).ComputeHash($Utf8.GetBytes($body)))).Replace('-', '').ToLowerInvariant()
$lf = [string][char]10
$canonical = 'POST' + $lf + $path + $lf + $timestamp + $lf + $nonce + $lf + $agentId + $lf + $bodyHash
$hmac = New-Object System.Security.Cryptography.HMACSHA256
$hmac.Key = $Utf8.GetBytes($secret)
try {
    $actual = ([BitConverter]::ToString($hmac.ComputeHash($Utf8.GetBytes($canonical)))).Replace('-', '').ToLowerInvariant()
} finally {
    $hmac.Dispose()
}
$expected = 'cfb401135e381d1ca7545862322640446e31304f949b7de5d2b67bb96fca2996'
if ($actual -ne $expected) {
    throw "HMAC vector mismatch. Actual=$actual"
}
Write-Host 'Windows PowerShell HMAC matches the PHP reference vector.'
