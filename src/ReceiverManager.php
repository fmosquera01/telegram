<?php

namespace GlpiPlugin\Telegram;

use RuntimeException;

/**
 * Controls the Telegram Long Polling receiver directly from the GLPI interface.
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
        $pluginRoot = dirname(__DIR__);
        $receiver = realpath($pluginRoot . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'telegram-receiver.php');
        if ($root === false || $receiver === false || !is_file($receiver)) {
            throw new RuntimeException('No se encuentra bin/telegram-receiver.php en el plugin instalado.');
        }
        $php = self::findPhpCli();
        return [$root, $receiver, $php];
    }

    private static function findPhpCli(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $candidates = [
                PHP_BINDIR . DIRECTORY_SEPARATOR . 'php',
                '/usr/bin/php',
                '/usr/local/bin/php',
                PHP_BINARY,
            ];
            $path = getenv('PATH') ?: '';
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                $directory = trim($directory);
                if ($directory !== '') {
                    $candidates[] = $directory . DIRECTORY_SEPARATOR . 'php';
                }
            }
            foreach (array_unique($candidates) as $candidate) {
                if (is_file($candidate) && is_executable($candidate)
                    && preg_match('/^php(?:[0-9.]+)?$/', basename($candidate))) {
                    return realpath($candidate) ?: $candidate;
                }
            }
            throw new RuntimeException('No se encontró un ejecutable PHP CLI en PHP_BINDIR, PATH o rutas habituales de Ubuntu.');
        }

        $candidates = [
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe',
            PHP_BINARY,
        ];
        // Laragon may load PHP as an Apache module, in which case PHP_BINARY
        // can point to httpd.exe rather than the PHP CLI executable.
        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = trim($directory, " \\t\\r\\n\\\"");
            if ($directory !== '') {
                $candidates[] = $directory . DIRECTORY_SEPARATOR . 'php.exe';
            }
        }
        $laragonRoot = dirname(dirname(GLPI_ROOT));
        $laragonPhp = $laragonRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php';
        if (is_dir($laragonPhp)) {
            foreach (glob($laragonPhp . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'php.exe') ?: [] as $candidate) {
                $candidates[] = $candidate;
            }
        }
        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && strcasecmp(basename($candidate), 'php.exe') === 0) {
                return realpath($candidate) ?: $candidate;
            }
        }
        throw new RuntimeException('No se encontró php.exe. Se revisaron PHP_BINDIR, PHP_BINARY, PATH y la carpeta bin\\php de Laragon.');
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
        return preg_match('/(?:^|\\R)\\s*RUNNING\\s*(?:\\R|$)/', self::powershell($script)) === 1;
    }

    private static function findRunningWindowsProcess(string $receiver): int
    {
        $script = '$matches = Get-CimInstance Win32_Process | Where-Object { '
            . '$_.Name -ieq "php.exe" -and $_.CommandLine -and $_.CommandLine.Contains('
            . "'" . str_replace("'", "''", $receiver) . "'"
            . ') }; if ($matches) { [Console]::WriteLine($matches[0].ProcessId) }';
        $output = self::powershell($script);
        if (preg_match_all('/(?:^|\\R)\\s*(\\d+)\\s*(?:\\R|$)/', $output, $matches) > 0) {
            return (int) end($matches[1]);
        }
        return 0;
    }

    private static function linuxProcess(int $pid, string $receiver): bool
    {
        if ($pid < 1 || !is_readable('/proc/' . $pid . '/cmdline')) {
            return false;
        }
        $commandLine = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
        return $commandLine !== '' && str_contains($commandLine, $receiver);
    }

    private static function findRunningLinuxProcess(string $receiver): int
    {
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlineFile) {
            $commandLine = (string) @file_get_contents($cmdlineFile);
            if ($commandLine !== '' && str_contains($commandLine, $receiver)) {
                $pid = (int) basename(dirname($cmdlineFile));
                if ($pid > 1 && $pid !== getmypid()) {
                    return $pid;
                }
            }
        }
        return 0;
    }

    private static function linuxStatus(): string
    {
        $file = self::pidFile();
        [, $receiver] = self::paths();
        $pid = is_file($file) ? (int) trim((string) file_get_contents($file)) : 0;
        if ($pid > 1 && self::linuxProcess($pid, $receiver)) {
            return 'running';
        }
        if (is_file($file)) {
            @unlink($file);
        }
        // Adopt a receiver launched before the PID file was persisted.
        $pid = self::findRunningLinuxProcess($receiver);
        if ($pid > 1) {
            file_put_contents($file, (string) $pid, LOCK_EX);
            return 'running';
        }
        return 'stopped';
    }

    private static function startLinux(): string
    {
        if (self::linuxStatus() === 'running') {
            return 'running';
        }
        [$root, $receiver, $php] = self::paths();
        $pidFile = self::pidFile();
        $logFile = self::stateDirectory() . DIRECTORY_SEPARATOR . 'receiver.log';
        $command = 'cd ' . escapeshellarg($root)
            . ' && nohup ' . escapeshellarg($php) . ' -f ' . escapeshellarg($receiver)
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        $pid = $code === 0 && isset($output[0]) ? (int) trim($output[0]) : 0;
        if ($pid < 2) {
            throw new RuntimeException('Ubuntu no devolvió un PID válido al iniciar el receptor PHP.');
        }
        file_put_contents($pidFile, (string) $pid, LOCK_EX);
        usleep(700000);
        if (self::linuxStatus() !== 'running') {
            @unlink($pidFile);
            $details = is_file($logFile) ? trim((string) file_get_contents($logFile)) : '';
            throw new RuntimeException('El receptor PHP terminó al iniciar en Ubuntu.'
                . ($details !== '' ? ' Detalle: ' . mb_substr($details, 0, 300) : ' Revise los permisos y el registro del receptor.'));
        }
        return 'running';
    }

    private static function stopLinux(): string
    {
        $file = self::pidFile();
        [, $receiver] = self::paths();
        $pid = is_file($file) ? (int) trim((string) file_get_contents($file)) : 0;
        if ($pid < 2 || !self::linuxProcess($pid, $receiver)) {
            @unlink($file);
            return 'stopped';
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        } else {
            exec('/bin/kill -TERM ' . $pid . ' 2>&1', $output, $code);
            if ($code !== 0) {
                throw new RuntimeException('No se pudo enviar la señal de detención al receptor PHP.');
            }
        }
        for ($attempt = 0; $attempt < 30; $attempt++) {
            if (!self::linuxProcess($pid, $receiver)) {
                @unlink($file);
                return 'stopped';
            }
            usleep(100000);
        }
        throw new RuntimeException('El receptor recibió la orden de detenerse, pero continúa activo.');
    }

    public static function status(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return self::linuxStatus();
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return 'unsupported';
        }

        $file = self::pidFile();
        [, $receiver] = self::paths();
        $pid = is_file($file) ? (int) trim((string) file_get_contents($file)) : 0;

        if ($pid > 0 && self::windowsProcess($pid, $receiver)) {
            return 'running';
        }

        if (is_file($file)) {
            @unlink($file);
        }

        // Adopt a receiver started manually from Cmder/PowerShell so the
        // plugin reports its real state and can control that same process.
        $pid = self::findRunningWindowsProcess($receiver);
        if ($pid > 0) {
            file_put_contents($file, (string) $pid, LOCK_EX);
            return 'running';
        }

        return 'stopped';
    }

    public static function start(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return self::startLinux();
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Sistema operativo no compatible para controlar el receptor.');
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
            . '[Console]::WriteLine("FML_RECEIVER_PID:" + $p.Id)';
        $output = self::powershell($script);
        if (preg_match('/(?:^|\\R)FML_RECEIVER_PID:(\\d+)(?:\\R|$)/', $output, $matches) !== 1) {
            throw new RuntimeException(
                'Windows inició el comando, pero no devolvió un PID identificable. Salida: '
                . mb_substr($output, 0, 500)
            );
        }
        $pid = (int) $matches[1];
        if ($pid < 1) {
            throw new RuntimeException('Windows devolvió un PID no válido para el receptor.');
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
        if (PHP_OS_FAMILY === 'Linux') {
            return self::stopLinux();
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Sistema operativo no compatible para controlar el receptor.');
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
