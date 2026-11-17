#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding()]
param(
    [string]$ConfigPath = 'C:\ProgramData\SongaADAgent\agent.json'
)
$ErrorActionPreference = 'Stop'
$utf8 = [Text.UTF8Encoding]::new($false)
$responsePath = Join-Path $env:TEMP ('fml-ad-test-' + [guid]::NewGuid().ToString('N') + '.json')
$bodyPath = Join-Path $env:TEMP ('fml-ad-test-' + [guid]::NewGuid().ToString('N') + '.body')
$keyBytes = $null
try {
    $config = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $uri = [Uri]$config.ApiUrl
    if ($uri.Scheme -ne 'https' -or $uri.Query -ne '' -or $uri.AbsolutePath -notmatch '/(plugins|marketplace)/telegram/front/ad-agent\.php$') {
        throw 'ApiUrl no es válida.'
    }
    Add-Type -AssemblyName System.Security
    $protected = [Convert]::FromBase64String([string]$config.SecretProtected)
    $plain = [System.Security.Cryptography.ProtectedData]::Unprotect($protected, $null, [System.Security.Cryptography.DataProtectionScope]::LocalMachine)
    $keyText = $utf8.GetString($plain)
    if ($keyText -notmatch '^[A-Za-z0-9_-]{43}$') { throw 'El secreto protegido no tiene el formato esperado.' }
    $keyBytes = $utf8.GetBytes($keyText)
    [Array]::Clear($plain, 0, $plain.Length)
    $keyText = $null

    # Acción deliberadamente no soportada: HTTP 400 unsupported_action
    # significa que HMAC fue validado. No reclama trabajos AD.
    $body = '{"action":"hmac_test"}'
    $timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
    $nonceBytes = New-Object byte[] 16
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($nonceBytes) } finally { $rng.Dispose() }
    $nonce = ([BitConverter]::ToString($nonceBytes)).Replace('-', '').ToLowerInvariant()
    # Hash and transmit the exact same UTF-8 bytes. Passing inline JSON as a
    # native curl.exe argument is unsafe in Windows PowerShell 5.1 because
    # embedded quotes may be altered by native argument parsing.
    $bodyBytes = $utf8.GetBytes($body)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { $bodyHash = ([BitConverter]::ToString($sha.ComputeHash($bodyBytes))).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
    [IO.File]::WriteAllBytes($bodyPath, $bodyBytes)
    $lf = [string][char]10
    $canonical = 'POST' + $lf + $uri.AbsolutePath + $lf + $timestamp + $lf + $nonce + $lf + [string]$config.AgentId + $lf + $bodyHash
    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    $hmac.Key = $keyBytes
    try { $signature = ([BitConverter]::ToString($hmac.ComputeHash($utf8.GetBytes($canonical)))).Replace('-', '').ToLowerInvariant() } finally { $hmac.Dispose() }

    $curl = (Get-Command curl.exe -ErrorAction Stop).Source
    $curlArguments = @('--silent','--show-error','--connect-timeout','10','--max-time','30','--output',$responsePath,'--write-out','%{http_code}','--request','POST',
        '--header','Content-Type: application/json',
        '--header',('X-FML-Agent: ' + [string]$config.AgentId),
        '--header',('X-FML-Timestamp: ' + $timestamp),
        '--header',('X-FML-Nonce: ' + $nonce),
        '--header',('X-FML-Signature: ' + $signature),
        '--data-binary',('@' + $bodyPath),$uri.AbsoluteUri)
    $status = (& $curl @curlArguments | Out-String).Trim()
    if ($LASTEXITCODE -ne 0) { throw "curl.exe falló: $LASTEXITCODE. La validación TLS permanece activa." }
    $response = if (Test-Path $responsePath) { [IO.File]::ReadAllText($responsePath, $utf8) } else { '' }
    if ($status -eq '400' -and $response -match '"error"\s*:\s*"unsupported_action"') {
        Write-Host 'OK: GLPI validó la firma HMAC y el agente.' -ForegroundColor Green
        Write-Host 'No se reclamó ni ejecutó ninguna solicitud de Active Directory.'
    } elseif ($status -eq '401') {
        $authReason = 'sin detalle'
        try {
            $errorPayload = $response | ConvertFrom-Json
            if ($errorPayload.reason -match '^[a-z_]+
        throw "Respuesta inesperada HTTP $status : $response"
    }
} finally {
    if ($null -ne $keyBytes) { [Array]::Clear($keyBytes, 0, $keyBytes.Length) }
    Remove-Item -LiteralPath $responsePath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $bodyPath -Force -ErrorAction SilentlyContinue
}) { $authReason = [string]$errorPayload.reason }
        } catch { }
        throw "HTTP 401: GLPI rechazó la autenticación del agente. Motivo: $authReason"
    } else {
        throw "Respuesta inesperada HTTP $status : $response"
    }
} finally {
    if ($null -ne $keyBytes) { [Array]::Clear($keyBytes, 0, $keyBytes.Length) }
    Remove-Item -LiteralPath $responsePath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $bodyPath -Force -ErrorAction SilentlyContinue
}