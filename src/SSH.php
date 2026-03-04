<?php

namespace App;

use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;

class SSH
{
    /**
     * Execute a command on a Proxmox node via SSH.
     *
     * Tries key-based auth first, falls back to password if configured.
     */
    public static function exec(string $host, string $command): string
    {
        $port = (int) Config::get('SSH_PORT', 22);
        $user = Config::get('SSH_USER', 'root');
        $keyPath = Config::get('SSH_KEY_PATH', '');
        $password = Config::get('SSH_PASSWORD', '');

        $ssh = new SSH2($host, $port, 10);

        $authenticated = false;

        // Try key-based auth
        if ($keyPath && file_exists($keyPath)) {
            $keyContents = file_get_contents($keyPath);
            $key = $password
                ? PublicKeyLoader::load($keyContents, $password)
                : PublicKeyLoader::load($keyContents);
            $authenticated = $ssh->login($user, $key);
        }

        // Fall back to password auth
        if (!$authenticated && $password) {
            $authenticated = $ssh->login($user, $password);
        }

        if (!$authenticated) {
            throw new \RuntimeException("SSH authentication failed for {$user}@{$host}:{$port}");
        }

        $output = $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();

        if ($exitCode !== 0) {
            throw new \RuntimeException("SSH command failed (exit {$exitCode}): " . trim($output));
        }

        return trim($output);
    }

    /**
     * Execute ha-manager maintenance command on the Proxmox host.
     */
    public static function enableNodeMaintenance(string $nodeName): string
    {
        $host = Config::get('PROXMOX_HOST');
        $cmd = 'ha-manager crm-command node-maintenance enable ' . escapeshellarg($nodeName);
        return self::exec($host, $cmd);
    }

    public static function disableNodeMaintenance(string $nodeName): string
    {
        $host = Config::get('PROXMOX_HOST');
        $cmd = 'ha-manager crm-command node-maintenance disable ' . escapeshellarg($nodeName);
        return self::exec($host, $cmd);
    }
}
