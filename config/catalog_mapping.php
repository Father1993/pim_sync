<?php
/**
 * Конфигурация маппинга каталогов PIM к витринам CS-Cart
 *
 * @package Tygh\Addons\PimSync\Config
 */

return [
    // Каталог ID 21 (тестовый каталог)
    '21' => [
        'company_id' => 2,      // Уровень
        'storefront_id' => 2,   // Используем активную витрину "Маркетплейс"
        'name' => 'Тестовый каталог ID 21',
        'description' => 'Тестовый каталог для продавца Уровень на витрине Маркетплейс',
        'auto_sync' => true,
        'sync_products' => true,
        'sync_categories' => true
    ],
    
    // Основные каталоги
    'master_catalog' => [
        'company_id' => 2,      // Меняем с 0 на 2 для Уровень
        'storefront_id' => 2,   // Используем активную витрину "Маркетплейс"
        'name' => 'Мастер каталог',
        'description' => 'Основной каталог с общими категориями на витрине Маркетплейс',
        'auto_sync' => true,
        'sync_products' => true,
        'sync_categories' => true
    ],
    
    // Каталоги для конкретных продавцов
    'uroven_catalog' => [
        'company_id' => 2,      // Уровень
        'storefront_id' => 1,   // Используем активную витрину "Маркетплейс"
        'name' => 'Каталог Уровень',
        'description' => 'Каталог для продавца Уровень на витрине Маркетплейс',
        'auto_sync' => true,
        'sync_products' => true,
        'sync_categories' => true
    ],
    
    'uroven2_catalog' => [
        'company_id' => 3,      // Уровень2
        'storefront_id' => 2,   // Используем активную витрину "Маркетплейс"
        'name' => 'Каталог Уровень2',
        'description' => 'Каталог для продавца Уровень2 на витрине Маркетплейс',
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
    
    // Конфигурация по умолчанию
    'default' => [
        'company_id' => 2,      // Меняем с 0 на 2 для Уровень (по умолчанию)
        'storefront_id' => 2,
        'name' => 'Каталог по умолчанию',
        'description' => 'Каталог по умолчанию для неопределенных каталогов',
        'auto_sync' => false,
        'sync_products' => true,
        'sync_categories' => true
    ]
]; 
