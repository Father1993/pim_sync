<?php

/**
 * @file: menu.post.php
 * @description: Схема меню админ-панели для аддона PIM Sync
 * @dependencies: CS-Cart menu system
 * @created: 2025-06-30
 */

if (! defined('BOOTSTRAP')) {
    die('Access denied');
}

// Добавляем пункт меню в раздел "Аддоны"
$schema['top']['addons']['items']['pim_sync'] = [
    'title' => __('pim_sync.sync_title'),
    'href' => 'pim_sync.manage',
    'position' => 500,
];

return $schema; 
