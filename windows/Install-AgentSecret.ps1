#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$ApiUrl,
    [string]$AgentId = 'SVRSNGPRTG',
    [string]$DomainController = 'SVRDC01.Songa.local',
    [string]$ServiceAccount = 'SONGA\svc_glpi_ad',
    [string]$ConfigDirectory = 'C:\ProgramData\SongaADAgent'
)

$ErrorActionPreference = 'Stop'
$directory = [IO.Path]::GetFullPath($ConfigDirectory)
$path = Join-Path $directory 'agent.json'
$uri = [Uri]$ApiUrl

if (-not $uri.IsAbsoluteUri -or $uri.Scheme -ne 'https' -or $uri.Query -ne '' -or $uri.AbsolutePath -notmatch '/(plugins|marketplace)/telegram/front/ad-agent\.php$') {
    throw 'ApiUrl debe ser HTTPS y apuntar al endpoint ad-agent.php de FML Telegram.'
}
if ($AgentId -notmatch '^[A-Za-z0-9._-]{1,64}$') {
    throw 'AgentId no es válido.'
}
if ($ServiceAccount -notmatch '^[A-Za-z0-9._-]+\\[A-Za-z0-9._-]+$') {
    throw 'ServiceAccount debe tener formato DOMINIO\cuenta.'
}

New-Item -ItemType Directory -Path $directory -Force | Out-Null
$grants = @(
    '*S-1-5-18:(OI)(CI)F',
    '*S-1-5-32-544:(OI)(CI)F',
    ($ServiceAccount + ':(OI)(CI)M')
)
& icacls.exe $directory /inheritance:r /grant:r $grants | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'No se pudieron establecer los permisos ACL del directorio de configuración.'
}

$secureSecret = Read-Host 'Pegue el secreto HMAC generado una sola vez en GLPI' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureSecret)
try {
    $plainSecret = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
}
if ($plainSecret -notmatch '^[A-Za-z0-9_-]{43}$') {
    throw 'El secreto debe tener 43 caracteres base64url (256 bits).'
}

Add-Type -AssemblyName System.Security
$secretBytes = [Text.Encoding]::UTF8.GetBytes($plainSecret)
$protectedBytes = [System.Security.Cryptography.ProtectedData]::Protect(
    $secretBytes,
    $null,
    [System.Security.Cryptography.DataProtectionScope]::LocalMachine
)
$config = [ordered]@{
    ApiUrl = $uri.AbsoluteUri
    AgentId = $AgentId
    DomainController = $DomainController
    PollSeconds = 2
    SecretProtected = [Convert]::ToBase64String($protectedBytes)
}
$json = $config | ConvertTo-Json -Depth 3
[IO.File]::WriteAllText($path, $json, [System.Text.UTF8Encoding]::new($false))
[Array]::Clear($secretBytes, 0, $secretBytes.Length)
$plainSecret = $null
Write-Host "Configuración protegida creada: $path"
Write-Host "Identidad configurada para ACL: $ServiceAccount"
Write-Host 'El secreto se cifra con DPAPI LocalMachine y el archivo queda limitado a SYSTEM, Administrators y la cuenta de servicio.'
