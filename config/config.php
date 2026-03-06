<?php

return [
    'PROXMOX_HOST'         => '192.168.12.75',
    'PROXMOX_FALLBACK_HOSTS' => '192.168.12.78,192.168.12.79',
    'PROXMOX_PORT'         => 8006,
    'PROXMOX_VERIFY_SSL'   => false,
    'PROXMOX_TOKEN_ID'     => 'root@pam!pdm-deploy',
    'PROXMOX_TOKEN_SECRET' => '5897768e-54b8-457a-8c04-0eff0b20eab7',
    'APP_SECRET'           => 'change-this-to-a-random-string',

    // SSH (for maintenance mode - ha-manager commands)
    'SSH_PORT'             => 22,
    'SSH_USER'             => 'root',
    'SSH_KEY_PATH'         => '/root/.ssh/id_rsa',
    'SSH_PASSWORD'         => 'F03nmann0815!',

    // EntraID / Azure AD (optional)
    'ENTRAID_TENANT_ID'    => '',
    'ENTRAID_CLIENT_ID'    => '',
    'ENTRAID_CLIENT_SECRET' => '',
    'ENTRAID_REDIRECT_URI' => '',
];
