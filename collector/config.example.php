<?php

// Inventario ficticio. Copie para config.php e ajuste no laboratorio.
return [
    'status_file' => __DIR__ . '/status.json',
    'status_group' => 'zabbix',
    'zabbix_server' => 'zabbix.example.invalid',
    'zabbix_port' => 10051,
    'zabbix_host' => 'vpn-lab',
    'zabbix_key' => 'pfsense.ipsec.raw',
    'vti' => [
        'ipsec101' => ['VPN 1', 'Link A', 6101],
        'ipsec102' => ['VPN 1', 'Link B', 6102],
    ],
    'policy' => [
        'con-lab-2' => [
            'unit' => 'VPN 2',
            'children' => ['child-lab-2a', 'child-lab-2b'],
        ],
    ],
];
