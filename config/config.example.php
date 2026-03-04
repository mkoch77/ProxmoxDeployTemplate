<?php

return [
    'PROXMOX_HOST'         => '192.168.1.100',
    'PROXMOX_PORT'         => 8006,
    'PROXMOX_VERIFY_SSL'   => false,
    'PROXMOX_TOKEN_ID'     => 'root@pam!deploy',
    'PROXMOX_TOKEN_SECRET' => '',
    'APP_SECRET'           => 'change-this-to-a-random-string',

    // SSH (for maintenance mode - ha-manager commands)
    'SSH_PORT'             => 22,
    'SSH_USER'             => 'root',
    'SSH_KEY_PATH'         => '/root/.ssh/id_rsa',
    'SSH_PASSWORD'         => '',

    // EntraID / Azure AD (optional)
    'ENTRAID_TENANT_ID'    => '',
    'ENTRAID_CLIENT_ID'    => '',
    'ENTRAID_CLIENT_SECRET' => '',
    'ENTRAID_REDIRECT_URI' => '',
];
