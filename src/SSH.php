<?php

namespace App;

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class SSH
{
    /**
     * Load the SSH private key contents.
     * Priority: SSH_PRIVATE_KEY (vault content) → SSH_KEY_PATH (file on disk).
     */
    private static function loadPrivateKey(): string
    {
        // 1. Key content directly from vault
        $keyContent = Config::get('SSH_PRIVATE_KEY', '');
        if ($keyContent) {
            return $keyContent;
        }

        // 2. Key file path fallback
        $keyPath = Config::get('SSH_KEY_PATH', '');
        if ($keyPath && file_exists($keyPath)) {
            return file_get_contents($keyPath);
        }

        return '';
    }

    /**
     * Authenticate an SSH2 connection.
     * Tries key-based auth first, falls back to password.
     */
    private static function authenticate(SSH2 $ssh): bool
    {
        $user = Config::get('SSH_USER', 'root');
        $password = Config::get('SSH_PASSWORD', '');
        $keyContents = self::loadPrivateKey();

        $authenticated = false;

        if ($keyContents) {
            $key = $password
                ? PublicKeyLoader::load($keyContents, $password)
                : PublicKeyLoader::load($keyContents);
            $authenticated = $ssh->login($user, $key);
        }

        if (!$authenticated && $password) {
            $authenticated = $ssh->login($user, $password);
        }

        return $authenticated;
    }

    /**
     * Execute a command on a Proxmox node via SSH.
     *
     * Tries key-based auth first, falls back to password if configured.
     */
    public static function exec(string $host, string $command, int $timeout = 30): string
    {
        $port = (int) Config::get('SSH_PORT', 22);
        $user = Config::get('SSH_USER', 'root');

        AppLogger::debug('ssh', "SSH exec connecting to {$host}", ['port' => $port, 'user' => $user]);

        $ssh = new SSH2($host, $port, 10);
        $ssh->setTimeout($timeout);

        if (!self::authenticate($ssh)) {
            AppLogger::debug('ssh', "SSH authentication failed for {$user}@{$host}:{$port}");
            throw new \RuntimeException("SSH authentication failed for {$user}@{$host}:{$port}");
        }

        AppLogger::debug('ssh', "SSH authenticated to {$host}", ['user' => $user]);

        $output = $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();

        if ($exitCode !== 0) {
            AppLogger::debug('ssh', "SSH command failed on {$host}", ['exit_code' => $exitCode]);
            throw new \RuntimeException("SSH command failed (exit {$exitCode}): " . trim($output));
        }

        AppLogger::debug('ssh', "SSH command completed on {$host}", ['exit_code' => $exitCode]);

        return trim($output);
    }

    /**
     * Execute a long-running install command via SSH.
     * Returns output + exit code without throwing on failure.
     * Timeout default: 300 seconds (5 min).
     */
    public static function execInstall(string $host, string $command, int $timeout = 300): array
    {
        $port = (int) Config::get('SSH_PORT', 22);
        $user = Config::get('SSH_USER', 'root');

        AppLogger::debug('ssh', "SSH execInstall connecting to {$host}", ['port' => $port, 'timeout' => $timeout]);

        $ssh = new SSH2($host, $port, $timeout);
        $ssh->setTimeout($timeout);

        if (!self::authenticate($ssh)) {
            AppLogger::debug('ssh', "SSH execInstall auth failed for {$user}@{$host}:{$port}");
            return [
                'output' => "SSH authentication failed for {$user}@{$host}:{$port}",
                'exit_code' => 1,
                'success' => false,
            ];
        }

        AppLogger::debug('ssh', "SSH execInstall authenticated to {$host}", ['user' => $user]);

        $output = $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();

        AppLogger::debug('ssh', "SSH execInstall completed on {$host}", ['exit_code' => $exitCode, 'success' => $exitCode === 0]);

        return [
            'output' => $output,
            'exit_code' => $exitCode,
            'success' => $exitCode === 0,
        ];
    }

    /**
     * Connect to a host and return an SSH2 object with PTY enabled.
     * Used for interactive terminal sessions.
     */
    public static function openInteractiveSession(string $host, int $timeout = 300): SSH2
    {
        $port = (int) Config::get('SSH_PORT', 22);
        $user = Config::get('SSH_USER', 'root');

        AppLogger::debug('ssh', "SSH interactive session opening to {$host}", ['port' => $port, 'timeout' => $timeout]);

        $ssh = new SSH2($host, $port, $timeout);
        $ssh->setTimeout(0.3);

        if (!self::authenticate($ssh)) {
            AppLogger::debug('ssh', "SSH interactive session auth failed for {$user}@{$host}:{$port}");
            throw new \RuntimeException("SSH authentication failed for {$user}@{$host}:{$port}");
        }

        AppLogger::debug('ssh', "SSH interactive session established to {$host}", ['user' => $user]);

        $ssh->enablePTY();

        return $ssh;
    }

    /**
     * Execute ha-manager maintenance command on the Proxmox host.
     */
    public static function enableNodeMaintenance(string $nodeName): string
    {
        $host = Config::get('PROXMOX_HOST');
        $cmd = 'ha-manager crm-command node-maintenance enable ' . escapeshellarg($nodeName);
        return self::exec($host, $cmd, 10);
    }

    public static function disableNodeMaintenance(string $nodeName): string
    {
        $host = Config::get('PROXMOX_HOST');
        $cmd = 'ha-manager crm-command node-maintenance disable ' . escapeshellarg($nodeName);
        return self::exec($host, $cmd, 10);
    }
}
