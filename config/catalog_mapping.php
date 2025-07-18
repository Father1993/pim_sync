<?php
/**
 * Конфигурация маппинга каталогов PIM к витринам CS-Cart
 *
 * @package Tygh\Addons\PimSync\Config
 */

return [
    // Основные каталоги
    'master_catalog' => [
        'company_id' => 0,      // Общие категории для всех
        'storefront_id' => 1,   // Основная витрина
        'name' => 'Мастер каталог',
        'description' => 'Основной каталог с общими категориями',
        'auto_sync' => true,
        'sync_products' => true,
        'sync_categories' => true
    ],
    
    // Каталоги для конкретных продавцов
    'uroven_catalog' => [
        'company_id' => 2,      // Уровень
        'storefront_id' => 1,
        'name' => 'Каталог Уровень',
        'description' => 'Каталог для продавца Уровень',
        'auto_sync' => true,
        'sync_products' => true,
        'sync_categories' => true
    ],
    
    'uroven2_catalog' => [
        'company_id' => 3,      // Уровень2
        'storefront_id' => 1,
        'name' => 'Каталог Уровень2',
        'description' => 'Каталог для продавца Уровень2',
        'auto_sync' => true,
        'sync_products' => true,
        'sync_categories' => true
    ],
    
    'local_catalog' => [
        'company_id' => 4,      // uroven.local
        'storefront_id' => 1,
        'name' => 'Каталог uroven.local',
        'description' => 'Каталог для продавца uroven.local',
        'auto_sync' => true,
        'sync_products' => true,
        'sync_categories' => true
    ],
    
    // Настройки по умолчанию
    'default' => [
        'company_id' => 0,
        'storefront_id' => 1,
        'name' => 'Каталог по умолчанию',
        'description' => 'Используется если не найден подходящий маппинг',
        'auto_sync' => false,
        'sync_products' => false,
        'sync_categories' => false
    ]
]; 
