<?php

/**
 * @file: config.php
 * @description: Конфигурационные константы для аддона PIM Sync
 * @dependencies: CS-Cart core
 * @created: 2025-07-02
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// Версия API PIM
define('PIM_SYNC_API_VERSION', 'v1');

// Таймауты для API запросов (в секундах)
define('PIM_SYNC_DEFAULT_TIMEOUT', 30);
define('PIM_SYNC_CONNECT_TIMEOUT', 10);

// Лимиты для синхронизации
define('PIM_SYNC_MAX_BATCH_SIZE', 100);
define('PIM_SYNC_MAX_RETRY_ATTEMPTS', 3);

// Интервалы синхронизации (в минутах)
define('PIM_SYNC_MIN_INTERVAL', 5);
define('PIM_SYNC_DEFAULT_INTERVAL', 30);

// Уровни логирования
define('PIM_SYNC_LOG_DEBUG', 'debug');
define('PIM_SYNC_LOG_INFO', 'info');
define('PIM_SYNC_LOG_WARNING', 'warning');
define('PIM_SYNC_LOG_ERROR', 'error');

// Статусы синхронизации
define('PIM_SYNC_STATUS_PENDING', 'pending');
define('PIM_SYNC_STATUS_SYNCED', 'synced');
define('PIM_SYNC_STATUS_ERROR', 'error');

// Типы синхронизации
define('PIM_SYNC_TYPE_FULL', 'full');
define('PIM_SYNC_TYPE_DELTA', 'delta');
define('PIM_SYNC_TYPE_MANUAL', 'manual');

// Типы сущностей
define('PIM_SYNC_ENTITY_CATEGORY', 'category');
define('PIM_SYNC_ENTITY_PRODUCT', 'product');
define('PIM_SYNC_ENTITY_FEATURE', 'feature');
define('PIM_SYNC_ENTITY_VARIANT', 'variant');

// Размер файла лога (в байтах) - 10MB
define('PIM_SYNC_MAX_LOG_SIZE', 10485760);

// Максимальное время выполнения синхронизации (в секундах) - 5 минут
define('PIM_SYNC_MAX_EXECUTION_TIME', 300);

// Пути к файлам
define('PIM_SYNC_LOG_FILE', 'pim_sync.log');
define('PIM_SYNC_TEMP_DIR', 'pim_sync');

// Настройки кеширования
define('PIM_SYNC_CACHE_LIFETIME', 3600); // 1 час

// User-Agent для API запросов
define('PIM_SYNC_USER_AGENT', 'CS-Cart PIM Sync/' . PIM_SYNC_API_VERSION); 
