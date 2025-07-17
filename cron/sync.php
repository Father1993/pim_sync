#!/usr/bin/env php
<?php

/**
 * @file: sync.php
 * @description: Cron-скрипт для автоматической синхронизации с Compo PIM
 * @dependencies: CS-Cart core
 * @created: 2025-06-30
 *
 * Использование:
 * php /path/to/cscart/app/addons/pim_sync/cron/sync.php [--full] [--days=N]
 *
 * Параметры:
 * --full   - выполнить полную синхронизацию
 * --days=N - синхронизировать товары за N дней (по умолчанию 1)
 *
 * Пример для crontab (каждые 30 минут):
 * 0,30 * * * * php /path/to/cscart/app/addons/pim_sync/cron/sync.php
 */

// Определяем корневую директорию CS-Cart
$root_dir = dirname(dirname(dirname(dirname(__DIR__))));
// Базовые константы для CS-Cart
define('AREA', 'C');
define('ACCOUNT_TYPE', 'admin');
define('NO_SESSION', true);
define('DESCR_SL', 'ru');
// Подключаем ядро CS-Cart
require $root_dir . '/init.php';
// Подключаем файлы аддона
require_once DIR_ROOT . '/app/addons/pim_sync/func.php';
// Парсим аргументы командной строки
$options = getopt('', ['full', 'days::']);
// Проверяем, включена ли синхронизация
$settings = fn_pim_sync_get_settings();

if (! $settings['sync_enabled']) {
    echo "PIM sync is disabled in settings\n";
    exit(0);
}

// Проверяем, не запущена ли уже синхронизация
$running_sync = db_get_field(
    "SELECT COUNT(*) FROM ?:pim_sync_log WHERE status = 'running'"
);

if ($running_sync > 0) {
    echo "Sync is already running\n";
    exit(0);
}

// Определяем тип синхронизации
$is_full_sync = isset($options['full']);
$days = isset($options['days']) ? intval($options['days']) : 1;

try {
    if ($is_full_sync) {
        echo "Starting full synchronization...\n";
        $result = fn_pim_sync_full();

        if ($result['success']) {
            echo "Full sync completed successfully!\n";
            echo "Categories synced: {$result['categories_synced']}\n";
            echo "Products synced: {$result['products_synced']}\n";
        } else {
            echo "Full sync failed!\n";
            echo "Errors: " . implode(', ', $result['errors']) . "\n";
            exit(1);
        }
    } else {
        echo "Starting delta synchronization for last $days day(s)...\n";
        $result = fn_pim_sync_delta($days);

        if ($result['success']) {
            echo "Delta sync completed successfully!\n";
            echo "Products updated: {$result['products_updated']}\n";
        } else {
            echo "Delta sync failed!\n";
            echo "Errors: " . implode(', ', $result['errors']) . "\n";
            exit(1);
        }
    }
    // Clearing old logs (older than 30 days)
    fn_pim_sync_cleanup_logs(30);

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
echo "Sync completed at " . date('Y-m-d H:i:s') . "\n";
exit(0);
