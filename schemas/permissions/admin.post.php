<?php

/**
 * @file: admin.post.php
 * @description: Схема прав доступа для контроллера pim_sync
 * @dependencies: CS-Cart permissions system
 * @created: 2025-06-30
 */

if (! defined('BOOTSTRAP')) {
    die('Access denied');
}

$schema['pim_sync'] = [
    'modes' => [
        'manage' => [
            'permissions' => 'view_catalog',
        ],
        'sync_full' => [
            'permissions' => 'manage_catalog',
        ],
        'sync_delta' => [
            'permissions' => 'manage_catalog',
        ],
        'test_connection' => [
            'permissions' => 'view_catalog',
        ],
        'clear_logs' => [
            'permissions' => 'manage_catalog',
        ],
        'log_details' => [
            'permissions' => 'view_catalog',
        ],
    ],
    'permissions' => 'view_catalog',
];

return $schema;
