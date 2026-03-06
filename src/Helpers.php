<?php

namespace App;

class Helpers
{
    public static function sanitizeString(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    public static function validateVmid(mixed $vmid): bool
    {
        return is_numeric($vmid) && (int)$vmid >= 100 && (int)$vmid <= 999999999;
    }

    public static function validateNodeName(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9\-]+$/', $name);
    }

    public static function validateVmName(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9\-\.]+$/', $name);
    }

    public static function validateType(string $type): bool
    {
        return in_array($type, ['qemu', 'lxc'], true);
    }

    public static function validatePowerAction(string $action): bool
    {
        return in_array($action, ['start', 'stop', 'shutdown', 'reboot', 'reset'], true);
    }

    public static function createAPI(): ProxmoxAPI
    {
        $primary = Config::get('PROXMOX_HOST');
        $fallbacks = array_filter(array_map(
            'trim',
            explode(',', Config::get('PROXMOX_FALLBACK_HOSTS', ''))
        ));
        $hosts = array_values(array_unique(array_merge([$primary], $fallbacks)));

        return new ProxmoxAPI(
            $hosts,
            (int) Config::get('PROXMOX_PORT', 8006),
            Config::get('PROXMOX_TOKEN_ID'),
            Config::get('PROXMOX_TOKEN_SECRET'),
            filter_var(Config::get('PROXMOX_VERIFY_SSL', false), FILTER_VALIDATE_BOOLEAN)
        );
    }
}
