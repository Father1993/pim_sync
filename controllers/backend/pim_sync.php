<?php

/**
 * @file: pim_sync.php
 * @description: Контроллер для управления PIM Sync
 * @dependencies: CS-Cart core, PIM Sync addon
 * @created: 2025-06-30
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

use Tygh\Registry;
use Tygh\Addons\PimSync\PimApiClient;

$mode = !empty($_REQUEST['mode']) ? $_REQUEST['mode'] : 'manage';

// Обработка POST запросов
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Запрос на тестирование соединения с PIM API
    if ($mode == 'test_connection') {
        try {
            // Создаем запись в БД для логирования теста
            $company_id = fn_get_runtime_company_id();
            $log_id = fn_pim_sync_create_log_entry('test_connection', $company_id);
            fn_pim_sync_log('=== НАЧАЛО ТЕСТИРОВАНИЯ СОЕДИНЕНИЯ ===', 'info');
            fn_pim_sync_log("Создана запись в БД с ID: $log_id", 'debug');
            
            // Получаем настройки аддона
            $settings = fn_pim_sync_get_settings();
            $api_url = $settings['api_url'];
            $api_login = $settings['api_login'];
            $api_password = $settings['api_password'];
            $catalog_id = $settings['catalog_id'];
            
            fn_pim_sync_log("API URL: $api_url", 'info');
            fn_pim_sync_log("API Login: $api_login", 'info');
            fn_pim_sync_log("Catalog ID: $catalog_id", 'info');
            
            $test_result = [
                'success' => false,
                'message' => '',
                'details' => [],
                'error' => null
            ];
            
            // Проверяем настройки
            if (empty($api_url) || empty($api_login) || empty($api_password)) {
                $test_result['message'] = __('pim_sync.error_no_settings');
                $test_result['error'] = 'Не заполнены обязательные поля: URL API, логин или пароль';
                fn_pim_sync_log('Test connection failed: missing settings', 'error');
                
                // Обновляем запись в БД - тест провален
                fn_pim_sync_update_log_entry($log_id, [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'status' => 'failed',
                    'error_details' => $test_result['error']
                ]);
            } else {
                // Используем PimApiClient для тестирования
                fn_pim_sync_log('Starting API connection test', 'info');
                $test_result['details'][] = 'Подключение к API: ' . $api_url;
                $test_result['details'][] = 'Логин: ' . $api_login;
                
                $api_client = new PimApiClient($api_url, $api_login, $api_password);
                
                // Проверяем базовое соединение (только авторизацию)
                if ($api_client->testConnection()) {
                    $test_result['success'] = true;
                    $test_result['message'] = 'Соединение установлено успешно';
                    $test_result['details'][] = 'Авторизация: успешно';
                    
                    if (!empty($catalog_id)) {
                        $test_result['details'][] = 'Каталог ID: ' . $catalog_id . ' (будет проверен при синхронизации)';
                    }
                    
                    fn_pim_sync_log('Test connection successful', 'info');
                    fn_set_notification('N', __('notice'), 'Соединение установлено успешно');
                    
                    // Обновляем запись в БД - тест успешен
                    fn_pim_sync_update_log_entry($log_id, [
                        'completed_at' => date('Y-m-d H:i:s'),
                        'status' => 'completed',
                        'affected_categories' => 0,
                        'affected_products' => 0
                    ]);
                } else {
                    $test_result['message'] = 'Ошибка подключения к API';
                    $test_result['error'] = 'Не удалось авторизоваться. Проверьте URL, логин и пароль.';
                    fn_pim_sync_log('Test connection failed: authentication error', 'error');
                    fn_set_notification('E', __('error'), 'Ошибка подключения к API');
                    
                    // Обновляем запись в БД - ошибка авторизации
                    fn_pim_sync_update_log_entry($log_id, [
                        'completed_at' => date('Y-m-d H:i:s'),
                        'status' => 'failed',
                        'error_details' => $test_result['error']
                    ]);
                }
            }
            
            // Передаем результат в view для немедленного отображения
            Registry::get('view')->assign([
                'test_result' => $test_result,
                'api_settings' => [
                    'api_url' => $api_url,
                    'api_login' => $api_login,
                    'catalog_id' => $catalog_id
                ]
            ]);
            
        } catch (Exception $e) {
            fn_pim_sync_log('Test connection error: ' . $e->getMessage(), 'error');
            $test_result = [
                'success' => false,
                'message' => 'Критическая ошибка при тестировании',
                'error' => $e->getMessage(),
                'details' => []
            ];
            
            // Обновляем запись в БД - критическая ошибка
            if (isset($log_id)) {
                fn_pim_sync_update_log_entry($log_id, [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'status' => 'failed',
                    'error_details' => $test_result['error']
                ]);
            }
            
            Registry::get('view')->assign([
                'test_result' => $test_result,
                'api_settings' => [
                    'api_url' => $api_url ?? '',
                    'api_login' => $api_login ?? '',
                    'catalog_id' => $catalog_id ?? ''
                ]
            ]);
        }
        
        fn_pim_sync_log('=== КОНЕЦ ТЕСТИРОВАНИЯ СОЕДИНЕНИЯ ===', 'info');
        
        // Возвращаемся на ту же страницу с результатом
        return [CONTROLLER_STATUS_OK, 'pim_sync.test_connection'];
    }
    
    // Запрос на полную синхронизацию
    if ($mode == 'sync_full') {
        try {
            fn_pim_sync_log('=== НАЧАЛО ПОЛНОЙ СИНХРОНИЗАЦИИ ===', 'info');
            
            // Получаем настройки аддона
            $settings = fn_pim_sync_get_settings();
            $catalog_id = $settings['catalog_id'];
            
            if (empty($catalog_id)) {
                fn_set_notification('E', __('error'), 'Не указан ID каталога в настройках');
                fn_pim_sync_log('Полная синхронизация не выполнена: не указан ID каталога', 'error');
                return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
            }
            
            // Создаем запись в БД для логирования синхронизации
            $company_id = fn_get_runtime_company_id();
            $log_id = fn_pim_sync_create_log_entry('full', $company_id);
            
            // Получаем сервис синхронизации
            $sync_service = fn_pim_sync_get_sync_service($company_id);
            
            // Запускаем полную синхронизацию для каталога
            $result = $sync_service->syncCatalog($catalog_id, 'full');
            
            if ($result['status'] === 'completed') {
                $categories_count = $result['categories']['total'];
                $products_count = $result['products']['total'];
                
                // Обновляем запись в БД - синхронизация успешна
                fn_pim_sync_update_log_entry($log_id, [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'status' => 'completed',
                    'affected_categories' => $categories_count,
                    'affected_products' => $products_count
                ]);
                
                fn_pim_sync_log("Полная синхронизация завершена успешно", 'info');
                fn_pim_sync_log("Категорий обработано: $categories_count", 'info');
                fn_pim_sync_log("Товаров обработано: $products_count", 'info');
                
                fn_set_notification('N', __('notice'), "Полная синхронизация завершена: $categories_count категорий, $products_count товаров");
            } else {
                $error_msg = isset($result['error']) ? $result['error'] : 'Неизвестная ошибка синхронизации';
                
                // Обновляем запись в БД - ошибка синхронизации
                fn_pim_sync_update_log_entry($log_id, [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'status' => 'failed',
                    'error_details' => $error_msg
                ]);
                
                fn_pim_sync_log('Полная синхронизация завершилась с ошибкой: ' . $error_msg, 'error');
                fn_set_notification('E', __('error'), 'Ошибка синхронизации: ' . $error_msg);
            }
            
            fn_pim_sync_log('=== КОНЕЦ ПОЛНОЙ СИНХРОНИЗАЦИИ ===', 'info');
            
        } catch (Exception $e) {
            // Обновляем запись в БД - критическая ошибка
            if (isset($log_id)) {
                fn_pim_sync_update_log_entry($log_id, [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'status' => 'failed',
                    'error_details' => $e->getMessage()
                ]);
            }
            
            fn_pim_sync_log('Критическая ошибка полной синхронизации: ' . $e->getMessage(), 'error');
            fn_set_notification('E', __('error'), 'Критическая ошибка синхронизации: ' . $e->getMessage());
        }
        return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
    }
    
    // Запрос на дельта синхронизацию
    if ($mode == 'sync_delta') {
        try {
            $days = isset($_POST['days']) ? (int)$_POST['days'] : 1;
            fn_pim_sync_log("=== НАЧАЛО ДЕЛЬТА СИНХРОНИЗАЦИИ (последние $days дней) ===", 'info');
            
            // Получаем настройки аддона
            $settings = fn_pim_sync_get_settings();
            $catalog_id = $settings['catalog_id'];
            
            if (empty($catalog_id)) {
                fn_set_notification('E', __('error'), 'Не указан ID каталога в настройках');
                fn_pim_sync_log('Дельта синхронизация не выполнена: не указан ID каталога', 'error');
                return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
            }
            
            // Создаем запись в БД для логирования синхронизации
            $company_id = fn_get_runtime_company_id();
            $log_id = fn_pim_sync_create_log_entry('delta', $company_id);
            
            // Получаем сервис синхронизации
            $sync_service = fn_pim_sync_get_sync_service($company_id);
            
            // Запускаем дельта синхронизацию для каталога
            $result = $sync_service->syncCatalog($catalog_id, 'delta', $days);
            
            if ($result['status'] === 'completed') {
                $categories_count = $result['categories']['total'];
                $products_count = $result['products']['total'];
                
                // Обновляем запись в БД - синхронизация успешна
                fn_pim_sync_update_log_entry($log_id, [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'status' => 'completed',
                    'affected_categories' => $categories_count,
                    'affected_products' => $products_count
                ]);
                
                fn_pim_sync_log("Дельта синхронизация завершена успешно", 'info');
                fn_pim_sync_log("Категорий обработано: $categories_count", 'info');
                fn_pim_sync_log("Товаров обработано: $products_count", 'info');
                
                fn_set_notification('N', __('notice'), "Дельта синхронизация завершена: $categories_count категорий, $products_count товаров");
            } else {
                $error_msg = isset($result['error']) ? $result['error'] : 'Неизвестная ошибка синхронизации';
                
                // Обновляем запись в БД - ошибка синхронизации
                fn_pim_sync_update_log_entry($log_id, [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'status' => 'failed',
                    'error_details' => $error_msg
                ]);
                
                fn_pim_sync_log('Дельта синхронизация завершилась с ошибкой: ' . $error_msg, 'error');
                fn_set_notification('E', __('error'), 'Ошибка дельта синхронизации: ' . $error_msg);
            }
            
            fn_pim_sync_log('=== КОНЕЦ ДЕЛЬТА СИНХРОНИЗАЦИИ ===', 'info');
            
        } catch (Exception $e) {
            // Обновляем запись в БД - критическая ошибка
            if (isset($log_id)) {
                fn_pim_sync_update_log_entry($log_id, [
                    'completed_at' => date('Y-m-d H:i:s'),
                    'status' => 'failed',
                    'error_details' => $e->getMessage()
                ]);
            }
            
            fn_pim_sync_log('Критическая ошибка дельта синхронизации: ' . $e->getMessage(), 'error');
            fn_set_notification('E', __('error'), 'Критическая ошибка дельта синхронизации: ' . $e->getMessage());
        }
        return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
    }
    
    // Запрос на очистку логов
    if ($mode == 'clear_logs') {
        $action = isset($_POST['action']) ? $_POST['action'] : '';
        $company_id = fn_get_runtime_company_id();
        
        try {
            $deleted_count = 0;
            
            if ($action == 'clear_all') {
                // Очищаем все логи для текущей компании
                $deleted_count = db_query("DELETE FROM ?:pim_sync_log WHERE company_id = ?i", $company_id);
                fn_pim_sync_clear_log_file(); // Очищаем файл логов
                fn_pim_sync_log("Cleared all logs for company $company_id: $deleted_count records deleted", 'info');
                fn_set_notification('N', __('notice'), __('pim_sync.cleared_all_logs', ['[count]' => $deleted_count]));
                
            } elseif ($action == 'clear_old') {
                // Очищаем логи старше 30 дней
                $cutoff_date = date('Y-m-d H:i:s', strtotime('-30 days'));
                $deleted_count = db_query("DELETE FROM ?:pim_sync_log WHERE company_id = ?i AND started_at < ?s", $company_id, $cutoff_date);
                fn_pim_sync_log("Cleared old logs for company $company_id: $deleted_count records deleted (older than 30 days)", 'info');
                fn_set_notification('N', __('notice'), __('pim_sync.cleared_old_logs', ['[count]' => $deleted_count]));
                
            } elseif ($action == 'clear_failed') {
                // Очищаем только неудачные записи
                $deleted_count = db_query("DELETE FROM ?:pim_sync_log WHERE company_id = ?i AND status = ?s", $company_id, 'failed');
                fn_pim_sync_log("Cleared failed logs for company $company_id: $deleted_count records deleted", 'info');
                fn_set_notification('N', __('notice'), __('pim_sync.cleared_failed_logs', ['[count]' => $deleted_count]));
            }
            
        } catch (Exception $e) {
            fn_pim_sync_log('Clear logs error: ' . $e->getMessage(), 'error');
            fn_set_notification('E', __('error'), __('pim_sync.clear_logs_error', ['[error]' => $e->getMessage()]));
        }
        
        // Добавляем параметр для принудительного обновления страницы
        $suffix = '?' . uniqid();
        return [CONTROLLER_STATUS_OK, 'pim_sync.clear_logs' . $suffix];
    }
}

// Режим отображения страницы тестирования
if ($mode == 'test_connection') {
    fn_pim_sync_log('Отображение страницы тестирования', 'info');
    
    // Получаем текущие настройки для отображения
    $settings = fn_pim_sync_get_settings();
    $api_settings = [
        'api_url' => $settings['api_url'],
        'api_login' => $settings['api_login'],
        'catalog_id' => $settings['catalog_id']
    ];
    
    // Получаем последние записи из БД для отображения
    $company_id = fn_get_runtime_company_id();
    $recent_logs = fn_pim_sync_get_log_entries(5, $company_id);
    
    // Получаем последние ошибки из лог-файла
    $recent_errors = fn_pim_sync_get_recent_errors(50);
    
    Registry::get('view')->assign([
        'api_settings' => $api_settings,
        'recent_logs' => $recent_logs,
        'recent_errors' => $recent_errors
    ]);
}

// Режим отображения страницы очистки логов
if ($mode == 'clear_logs') {
    $company_id = fn_get_runtime_company_id();
    
    try {
        // Получаем статистику логов
        $log_stats = [
            'total_entries' => (int)db_get_field("SELECT COUNT(*) FROM ?:pim_sync_log WHERE company_id = ?i", $company_id),
            'completed_entries' => (int)db_get_field("SELECT COUNT(*) FROM ?:pim_sync_log WHERE company_id = ?i AND status = ?s", $company_id, 'completed'),
            'failed_entries' => (int)db_get_field("SELECT COUNT(*) FROM ?:pim_sync_log WHERE company_id = ?i AND status = ?s", $company_id, 'failed'),
            'running_entries' => (int)db_get_field("SELECT COUNT(*) FROM ?:pim_sync_log WHERE company_id = ?i AND status = ?s", $company_id, 'running'),
            'old_entries' => (int)db_get_field("SELECT COUNT(*) FROM ?:pim_sync_log WHERE company_id = ?i AND started_at < ?s", $company_id, date('Y-m-d H:i:s', strtotime('-30 days')))
        ];
        
        // Получаем последние записи для предварительного просмотра
        $recent_logs = fn_pim_sync_get_log_entries(10, $company_id);
        
        // Получаем информацию о файле логов
        $log_file = fn_pim_sync_get_log_file_path();
        clearstatcache();
        
        $log_file_exists = file_exists($log_file);
        $log_file_size = $log_file_exists ? filesize($log_file) : 0;
        
        // Отладочная информация
        fn_print_r('Debug info:');
        fn_print_r('Log file path: ' . $log_file);
        fn_print_r('File exists: ' . ($log_file_exists ? 'Yes' : 'No'));
        fn_print_r('File size: ' . $log_file_size);
        fn_print_r('Current working directory: ' . getcwd());
        
        // Формируем информацию о файле
        $log_file_info = [
            'path' => $log_file,
            'exists' => $log_file_exists,
            'size' => $log_file_size
        ];
        
        // Отладочная информация о массиве
        fn_print_r('Log file info array:', $log_file_info);
        
        // Читаем содержимое файла
        $log_content = '';
        if ($log_file_exists) {
            $log_content = file_get_contents($log_file);
            fn_print_r('Log content length: ' . strlen($log_content));
        }
        
        // Передаем данные в шаблон
        Registry::get('view')->assign([
            'log_stats' => $log_stats,
            'recent_logs' => $recent_logs,
            'log_file_info' => $log_file_info,
            'log_content' => $log_content
        ]);
        
    } catch (Exception $e) {
        fn_pim_sync_log('Clear logs page error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), __('pim_sync.clear_logs_page_error', ['[error]' => $e->getMessage()]));
    }
}

// Основной режим - отображаем страницу управления
if ($mode == 'manage') {
    try {   
        // Получаем реальные данные из БД
        $company_id = fn_get_runtime_company_id();
        $sync_logs = fn_pim_sync_get_log_entries(10, $company_id);
        
        // Подсчитываем статистику из реальных данных
        $stats = [
            'total_categories' => db_get_field("SELECT COUNT(*) FROM ?:categories WHERE company_id = ?i", $company_id),
            'total_products' => db_get_field("SELECT COUNT(*) FROM ?:products WHERE company_id = ?i", $company_id),
            'last_sync' => !empty($sync_logs) ? fn_date_format(strtotime($sync_logs[0]['started_at']) ?: TIME) : __('pim_sync.never'),
            'sync_errors' => db_get_field("SELECT COUNT(*) FROM ?:pim_sync_log WHERE company_id = ?i AND status = ?s", $company_id, 'failed')
        ];
        
        Registry::get('view')->assign([
            'pim_stats' => $stats,
            'sync_logs' => $sync_logs
        ]);
    } catch (Exception $e) {
        fn_pim_sync_log('Management page error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Ошибка загрузки страницы: ' . $e->getMessage());
    }
}

// Режим отображения страницы полной синхронизации
if ($mode == 'sync_full') {
    try {
        $company_id = fn_get_runtime_company_id();
        
        // Получаем статистику из PIM через SyncService
        $sync_service = fn_pim_sync_get_sync_service($company_id);
        $pim_stats = $sync_service->getSyncStats();
        
        // Получаем последние записи синхронизации
        $sync_logs = fn_pim_sync_get_log_entries(10, $company_id);
        
        // Получаем последнюю синхронизацию
        $last_sync = !empty($sync_logs) ? $sync_logs[0] : null;
        
        // Проверяем, есть ли текущая синхронизация
        $current_sync = null;
        $running_sync = db_get_row("SELECT * FROM ?:pim_sync_log WHERE company_id = ?i AND status = ?s ORDER BY started_at DESC LIMIT 1", 
            $company_id, 'running');
        
        if ($running_sync) {
            $current_sync = [
                'stage' => 'Выполняется',
                'progress' => 50, // Примерное значение
                'processed' => 0,
                'total' => $pim_stats['categories']['total'] + $pim_stats['products']['total']
            ];
        }
        
        Registry::get('view')->assign([
            'pim_stats' => $pim_stats,
            'sync_logs' => $sync_logs,
            'last_sync' => $last_sync,
            'current_sync' => $current_sync
        ]);
        
    } catch (Exception $e) {
        fn_pim_sync_log('Sync full page error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Ошибка загрузки страницы синхронизации: ' . $e->getMessage());
        
        // Передаем пустые данные в случае ошибки
        Registry::get('view')->assign([
            'pim_stats' => [
                'categories' => ['total' => 0, 'synced' => 0],
                'products' => ['total' => 0, 'synced' => 0],
                'last_sync' => null
            ],
            'sync_logs' => [],
            'last_sync' => null,
            'current_sync' => null
        ]);
    }
}

// Запрос на очистку категорий перед синхронизацией
if ($mode == 'cleanup_categories') {
try {
    fn_pim_sync_log('Categories cleanup started');
    fn_pim_sync_cleanup_categories();
    fn_set_notification('N', __('notice'), 'Категории очищены перед синхронизацией');
} catch (Exception $e) {
    fn_pim_sync_log('Categories cleanup error: ' . $e->getMessage(), 'error');
    fn_set_notification('E', __('error'), 'Ошибка очистки категорий: ' . $e->getMessage());
}
return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
}
