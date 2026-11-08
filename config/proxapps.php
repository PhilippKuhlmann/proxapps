<?php

return [

    /*
    | Directory holding the app catalog (catalog/<id>/app.yaml + install script).
    */
    'catalog_dir' => env('PROXAPPS_CATALOG', base_path('catalog')),

    /*
    | Base provisioning script that runs in every new container before the app installer.
    */
    'base_script' => env('PROXAPPS_BASE_SCRIPT', resource_path('provisioning/base.sh')),

    /*
    | Private key of the deployer SSH key pair (ed25519, created on first use).
    */
    'ssh_key_path' => env('PROXAPPS_SSH_KEY', storage_path('app/private/ssh/id_ed25519')),

    /*
    | Seconds between Proxmox task status polls and between retries (SSH, DHCP, health check).
    */
    'poll_interval' => (float) env('PROXAPPS_POLL_INTERVAL', 1.5),
    'retry_interval' => (float) env('PROXAPPS_RETRY_INTERVAL', 3),

    /*
    | Upper limit (seconds) for one whole deployment run. Null = job timeout minus 5 minutes
    | (RunDeployment::TIMEOUT − 300); larger values are capped to that.
    */
    'deploy_budget' => env('PROXAPPS_DEPLOY_BUDGET'),

    /*
    | How long the list of used IP addresses per connection is cached.
    */
    'used_ips_cache_seconds' => 20,

    /*
    | Defaults for the settings stored in the "settings" table.
    */
    'defaults' => [
        // Applies when a network profile has no own pattern: IP 10.10.250.55 → VMID 250055.
        'vmid_pattern' => '{o3}{o4:03}',
        // Central updates
        'auto_update_enabled' => false,
        'auto_update_time' => '03:00',
        'auto_update_last_run' => '',
        'snapshot_before_update' => true,
        'auto_rollback' => true,
        'keep_snapshots' => 3,
        'vmid_min' => 100,
        'vmid_max' => 999999,
        'default_tags' => 'proxapps',
        'timezone' => 'Europe/Berlin',
        'apt_upgrade' => true,
        'destroy_on_failure' => false,
        'authorized_keys' => '',
    ],
];
