#requires -Version 5.1
$ErrorActionPreference = 'Stop'
$ConfigPath = 'C:\ProgramData\SongaADAgent\agent.json'
$LogPath = 'C:\ProgramData\SongaADAgent\agent.log'
$Utf8 = [System.Text.UTF8Encoding]::new($false)

function Write-AgentLog {
    param([string]$Message)
    $line = '[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    try { Add-Content -LiteralPath $LogPath -Value $line -Encoding UTF8 } catch { Write-Output $line }
}
function ConvertTo-Hex {
    param([byte[]]$Bytes)
    return ([BitConverter]::ToString($Bytes)).Replace('-', '').ToLowerInvariant()
}

if (-not (Test-Path -LiteralPath $ConfigPath -PathType Leaf)) {
    throw "No existe la configuración protegida: $ConfigPath"
}
$Config = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8 | ConvertFrom-Json
$ApiUri = [Uri]$Config.ApiUrl
if (-not $ApiUri.IsAbsoluteUri -or $ApiUri.Scheme -ne 'https' -or $ApiUri.Query -ne '') {
    throw 'ApiUrl debe ser una URL HTTPS sin query string.'
}
if ($ApiUri.AbsolutePath -notmatch '/(plugins|marketplace)/telegram/front/ad-agent\.php$') {
    throw 'ApiUrl no apunta al endpoint AD permitido de FML Telegram.'
}
if ([string]$Config.AgentId -notmatch '^[A-Za-z0-9._-]{1,64}$') {
    throw 'AgentId no es válido.'
}
$PollSeconds = [Math]::Max(1, [Math]::Min(10, [int]$Config.PollSeconds))

Add-Type -AssemblyName System.Security
$protectedBytes = [Convert]::FromBase64String([string]$Config.SecretProtected)
$secretBytes = [System.Security.Cryptography.ProtectedData]::Unprotect(
    $protectedBytes, $null, [System.Security.Cryptography.DataProtectionScope]::LocalMachine
)
$secretText = $Utf8.GetString($secretBytes)
if ($secretText -notmatch '^[A-Za-z0-9_-]{43}$') {
    throw 'El secreto HMAC descifrado no tiene el formato esperado.'
}
$script:SecretBytes = $Utf8.GetBytes($secretText)
[Array]::Clear($secretBytes, 0, $secretBytes.Length)
$secretText = $null
$protectedBytes = $null

Import-Module ActiveDirectory -ErrorAction Stop

function Invoke-AgentApi {
    param([hashtable]$Payload)

    $body = ConvertTo-Json -InputObject $Payload -Depth 6 -Compress
    $bodyBytes = $Utf8.GetBytes($body)
    $timestamp = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds().ToString()
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $nonceBytes = New-Object byte[] 16
    try { $rng.GetBytes($nonceBytes) } finally { $rng.Dispose() }
    $nonce = ConvertTo-Hex $nonceBytes

    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { $bodyHash = ConvertTo-Hex ($sha.ComputeHash($bodyBytes)) } finally { $sha.Dispose() }
    $lf = [string][char]10
    $canonical = 'POST' + $lf + $ApiUri.AbsolutePath + $lf +
        $timestamp + $lf + $nonce + $lf +
        [string]$Config.AgentId + $lf + $bodyHash

    $hmac = New-Object System.Security.Cryptography.HMACSHA256
    $hmac.Key = $script:SecretBytes
    try { $signature = ConvertTo-Hex ($hmac.ComputeHash($Utf8.GetBytes($canonical))) } finally { $hmac.Dispose() }

    # curl.exe validates the server certificate and avoids PS 5.1/.NET TLS negotiation failures.
    $curl = (Get-Command curl.exe -ErrorAction Stop).Source
    $responsePath = Join-Path $env:TEMP ('fml-ad-agent-' + [guid]::NewGuid().ToString('N') + '.json')
    $bodyPath = Join-Path $env:TEMP ('fml-ad-agent-' + [guid]::NewGuid().ToString('N') + '.body')
    try {
        [IO.File]::WriteAllBytes($bodyPath, $bodyBytes)
        $curlArguments = @(
            '--silent', '--show-error', '--connect-timeout', '10', '--max-time', '30',
            '--output', $responsePath, '--write-out', '%{http_code}',
            '--request', 'POST',
            '--header', 'Content-Type: application/json',
            '--header', ('X-FML-Agent: ' + [string]$Config.AgentId),
            '--header', ('X-FML-Timestamp: ' + $timestamp),
            '--header', ('X-FML-Nonce: ' + $nonce),
            '--header', ('X-FML-Signature: ' + $signature),
            '--data-binary', ('@' + $bodyPath),
            $ApiUri.AbsoluteUri
        )
        $httpStatusText = (& $curl @curlArguments | Out-String).Trim()
        $curlExitCode = $LASTEXITCODE
        if ($curlExitCode -ne 0) { throw "curl.exe falló con código $curlExitCode." }

        $responseContent = if (Test-Path -LiteralPath $responsePath) {
            [IO.File]::ReadAllText($responsePath, $Utf8)
        } else { '' }
        if ($httpStatusText -notmatch '^\d{3}$') {
            throw 'curl.exe no devolvió un estado HTTP válido.'
        }
        $httpStatus = [int]$httpStatusText
        if ($httpStatus -lt 200 -or $httpStatus -ge 300) {
            if ($responseContent.Length -gt 300) { $responseContent = $responseContent.Substring(0, 300) }
            throw "GLPI respondió HTTP $httpStatus : $responseContent"
        }
        return ($responseContent | ConvertFrom-Json)
    } finally {
        Remove-Item -LiteralPath $responsePath -Force -ErrorAction SilentlyContinue
        Remove-Item -LiteralPath $bodyPath -Force -ErrorAction SilentlyContinue
    }
}

function Get-AdOutcome {
    param([string]$Operation, [string]$SamAccountName)
    try {
        if ($Operation -eq 'list_locked_accounts') {
            $lockedUsers = @(Search-ADAccount -LockedOut -UsersOnly -Server $Config.DomainController -ErrorAction Stop)
            $accounts = @()
            foreach ($lockedUser in $lockedUsers) {
                $candidate = Get-ADUser -Identity $lockedUser.DistinguishedName -Server $Config.DomainController -Properties Enabled, LockedOut, DisplayName -ErrorAction Stop
                # Search-ADAccount can return disabled users too; never expose them in this list.
                if ([bool]$candidate.Enabled -and [bool]$candidate.LockedOut) {
                    $displayName = [string]$candidate.DisplayName
                    if ([string]::IsNullOrWhiteSpace($displayName)) { $displayName = [string]$candidate.SamAccountName }
                    if ($displayName.Length -gt 120) { $displayName = $displayName.Substring(0, 120) }
                    $accounts += [ordered]@{
                        sam_account_name = [string]$candidate.SamAccountName
                        display_name = $displayName
                    }
                }
            }
            $accounts = @($accounts | Sort-Object sam_account_name | Select-Object -First 500)
            return @{ result_code = 'ok'; data = @{ accounts = $accounts } }
        }

        $user = Get-ADUser -Identity $SamAccountName -Server $Config.DomainController -Properties Enabled, LockedOut -ErrorAction Stop
        if ($Operation -eq 'account_status') {
            return @{ result_code = 'ok'; data = @{ enabled = [bool]$user.Enabled; locked_out = [bool]$user.LockedOut } }
        }
        if ($Operation -eq 'unlock_account') {
            # Disabled accounts are explicitly out of scope. Do not enable or modify them.
            if (-not [bool]$user.Enabled) {
                return @{ result_code = 'account_disabled'; data = @{} }
            }
            if (-not [bool]$user.LockedOut) {
                return @{ result_code = 'already_unlocked'; data = @{} }
            }
            Unlock-ADAccount -Identity $user.DistinguishedName -Server $Config.DomainController -Confirm:$false -ErrorAction Stop
            $verified = Get-ADUser -Identity $SamAccountName -Server $Config.DomainController -Properties LockedOut -ErrorAction Stop
            if ([bool]$verified.LockedOut) { return @{ result_code = 'error'; data = @{} } }
            return @{ result_code = 'unlocked'; data = @{} }
        }
        Write-AgentLog "Operación rechazada por no estar en allowlist: $Operation"
        return $null
    } catch {
        $typeName = $_.Exception.GetType().FullName
        $message = [string]$_.Exception.Message
        if ($typeName -match 'ADIdentityNotFoundException' -or $message -match 'cannot find an object with identity') {
            return @{ result_code = 'not_found'; data = @{} }
        }
        if ($typeName -match 'UnauthorizedAccessException|SecurityException' -or $message -match 'access is denied|insufficient access rights|unauthorized') {
            return @{ result_code = 'access_denied'; data = @{} }
        }
        Write-AgentLog "Error AD controlado ($Operation): $typeName"
        return @{ result_code = 'error'; data = @{} }
    }
}

Write-AgentLog "Agente iniciado. AgentId=$($Config.AgentId); DC=$($Config.DomainController)"
while ($true) {
    try {
        $claim = Invoke-AgentApi @{ action = 'claim' }
        if (-not $claim.success) { throw 'GLPI rechazó la solicitud de claim.' }
        if ($null -eq $claim.request) {
            Start-Sleep -Seconds $PollSeconds
            continue
        }
        $request = $claim.request
        $outcome = Get-AdOutcome -Operation ([string]$request.operation) -SamAccountName ([string]$request.sam_account_name)
        if ($null -eq $outcome) {
            Write-AgentLog "Solicitud con operación inesperada. UUID=$($request.request_uuid)"
            Start-Sleep -Seconds $PollSeconds
            continue
        }
        $completion = @{
            action = 'complete'
            request_uuid = [string]$request.request_uuid
            lease_token = [string]$request.lease_token
            result_code = [string]$outcome.result_code
            data = $outcome.data
        }
        $result = Invoke-AgentApi $completion
        if (-not $result.success) { throw "GLPI no aceptó la finalización. UUID=$($request.request_uuid)" }
        Write-AgentLog "Solicitud finalizada. UUID=$($request.request_uuid); estado=$($result.status); código=$($result.result_code)"
    } catch {
        Write-AgentLog "Error de comunicación/proceso: $($_.Exception.GetType().FullName)"
        Start-Sleep -Seconds 3
    }
}