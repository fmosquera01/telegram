<?php

namespace GlpiPlugin\\Telegram;

use RuntimeException;

/**
 * Minimal local-process control for the Telegram Long Polling receiver.
 *
 * Windows support is used by the Laragon lab. Linux production is intentionally
 * left for the systemd integration so the web process never manages daemons
 * with broad shell permissions.
 */
final class ReceiverManager
{
    private static function stateDirectory(): string
    {
        if (!defined('GLPI_VAR_DIR')) {
            throw new RuntimeException('GLPI_VAR_DIR no está disponible.');
        }
        $directory = GLPI_VAR_DIR . DIRECTORY_SEPARATOR . '_plugins'
            . DIRECTORY_SEPARATOR . 'telegram';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio de estado del receptor.');
        }
        return $directory;
    }

    private static function pidFile(): string
    {
        return self::stateDirectory() . DIRECTORY_SEPARATOR . 'receiver.pid';
    }

    private static function paths(): array
    {
        if (!defined('GLPI_ROOT')) {
            throw new RuntimeException('GLPI_ROOT no está disponible.');
        }
        $root = realpath(GLPI_ROOT);
        $receiver = realpath(GLPI_ROOT . DIRECTORY_SEPARATOR . 'plugins'
            . DIRECTORY_SEPARATOR . 'telegram' . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'telegram-receiver.php');
        if ($root === false || $receiver === false || !is_file($receiver)) {
            throw new RuntimeException('No se encuentra bin/telegram-receiver.php en el plugin instalado.');
        }
        $php = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
        if (!is_file($php)) {
            $php = PHP_BINARY;
        }
        if (!is_file($php) || stripos(basename($php), 'php') !== 0) {
            throw new RuntimeException('No se encontró php.exe para ejecutar el receptor desde Laragon.');
        }
        return [$root, $receiver, $php];
    }

    private static function powershell(string $script): string
    {
        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $command = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ' . $encoded;
        $output = [];
        $code = 0;
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('No se pudo controlar el receptor: ' . implode(' ', $output));
        }
        return trim(implode("\n", $output));
    }

    private static function windowsProcess(int $pid, string $receiver): bool
    {
        $script = '$p = Get-CimInstance Win32_Process -Filter "ProcessId = ' . $pid . '"; '
            . 'if ($null -ne $p -and $p.CommandLine -and $p.CommandLine.Contains('
            . "'" . str_replace("'", "''", $receiver) . "'"
            . ')) { "RUNNING" } else { "STOPPED" }';
        return self::powershell($script) === 'RUNNING';
    }

    public static function status(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'unsupported';
        }
        $file = self::pidFile();
        if (!is_file($file)) {
            return 'stopped';
        }
        $pid = (int) trim((string) file_get_contents($file));
        if ($pid < 1) {
            @unlink($file);
            return 'stopped';
        }
        [, $receiver] = self::paths();
        if (self::windowsProcess($pid, $receiver)) {
            return 'running';
        }
        @unlink($file);
        return 'stopped';
    }

    public static function start(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('El control directo está habilitado para Laragon. En Ubuntu se utilizará systemd.');
        }
        if (self::status() === 'running') {
            return 'running';
        }
        [$root, $receiver, $php] = self::paths();
        $pidFile = self::pidFile();
        $script = '$ErrorActionPreference = "Stop"; '
            . '$p = Start-Process -FilePath ' . "'" . str_replace("'", "''", $php) . "'"
            . ' -ArgumentList @("-f", ' . "'" . str_replace("'", "''", $receiver) . "')"
            . ' -WorkingDirectory ' . "'" . str_replace("'", "''", $root) . "'"
            . ' -WindowStyle Hidden -PassThru; '
            . 'Start-Sleep -Milliseconds 700; '
            . 'if ($p.HasExited) { throw "El receptor terminó al iniciar." }; '
            . '[Console]::WriteLine($p.Id)';
        $pid = (int) self::powershell($script);
        if ($pid < 1) {
            throw new RuntimeException('Windows no devolvió el PID del receptor.');
        }
        file_put_contents($pidFile, (string) $pid, LOCK_EX);
        if (self::status() !== 'running') {
            @unlink($pidFile);
            throw new RuntimeException('El proceso del receptor no quedó activo.');
        }
        return 'running';
    }

    public static function stop(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('El control directo está habilitado para Laragon. En Ubuntu se utilizará systemd.');
        }
        $file = self::pidFile();
        if (!is_file($file)) {
            return 'stopped';
        }
        $pid = (int) trim((string) file_get_contents($file));
        if ($pid < 1) {
            @unlink($file);
            return 'stopped';
        }
        [, $receiver] = self::paths();
        $script = '$p = Get-CimInstance Win32_Process -Filter "ProcessId = ' . $pid . '"; '
            . 'if ($null -ne $p -and $p.CommandLine -and $p.CommandLine.Contains('
            . "'" . str_replace("'", "''", $receiver) . "'"
            . ')) { Stop-Process -Id ' . $pid . ' -Force; "STOPPED" } else { "STOPPED" }';
        self::powershell($script);
        @unlink($file);
        return 'stopped';
    }
}
