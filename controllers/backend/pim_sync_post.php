<?php
/**
 * @file: pim_sync_post.php
 * @description: Обработчики POST запросов для контроллера PIM Sync
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

use Tygh\Registry;
use Tygh\Addons\PimSync\Api\PimApiClient;
use Tygh\Addons\PimSync\Exception\ApiAuthException;

// Обработка различных POST запросов
switch ($mode) {
    case 'test_connection':
        fn_pim_sync_process_test_connection();
        break;
        
    case 'sync_full':
        fn_pim_sync_process_sync_full();
        break;
        
    case 'sync_delta':
        fn_pim_sync_process_sync_delta();
        break;
        
    case 'clear_logs':
        fn_pim_sync_process_clear_logs();
        break;
        
    default:
        return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
}

/**
 * Обработка запроса на тестирование соединения
 */
function fn_pim_sync_process_test_connection()
{
    try {
        fn_pim_sync_log('=== НАЧАЛО ТЕСТИРОВАНИЯ СОЕДИНЕНИЯ ===', 'info');
        
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
        } else {
            // Используем PimApiClient для тестирования
            fn_pim_sync_log('Starting API connection test', 'info');
            $test_result['details'][] = 'Подключение к API: ' . $api_url;
            $test_result['details'][] = 'Логин: ' . $api_login;
            
            try {
                $api_client = new PimApiClient($api_url, $api_login, $api_password, fn_pim_sync_get_logger());
                
                // Проверяем базовое соединение
                if ($api_client->testConnection()) {
                    $test_result['success'] = true;
                    $test_result['message'] = 'Соединение установлено успешно';
                    $test_result['details'][] = 'Авторизация: успешно';
                    
                    if (!empty($catalog_id)) {
                        $test_result['details'][] = 'Каталог ID: ' . $catalog_id;
                    }
                    
                    fn_pim_sync_log('Test connection successful', 'info');
                    fn_set_notification('N', __('notice'), 'Соединение установлено успешно');
                } else {
                    $test_result['message'] = 'Ошибка подключения к API';
                    $test_result['error'] = 'Не удалось авторизоваться. Проверьте URL, логин и пароль.';
                    fn_pim_sync_log('Test connection failed: authentication error', 'error');
                    fn_set_notification('E', __('error'), 'Ошибка подключения к API');
                }
            } catch (ApiAuthException $e) {
                $test_result['message'] = 'Ошибка авторизации в API';
                $test_result['error'] = $e->getMessage();
                fn_pim_sync_log('API authentication error: ' . $e->getMessage(), 'error');
                fn_set_notification('E', __('error'), 'Ошибка авторизации: ' . $e->getMessage());
            } catch (Exception $e) {
                $test_result['message'] = 'Критическая ошибка при подключении';
                $test_result['error'] = $e->getMessage();
                fn_pim_sync_log('Critical connection error: ' . $e->getMessage(), 'error');
                fn_set_notification('E', __('error'), 'Ошибка: ' . $e->getMessage());
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
        
        fn_pim_sync_log('=== КОНЕЦ ТЕСТИРОВАНИЯ СОЕДИНЕНИЯ ===', 'info');
        
        return [CONTROLLER_STATUS_OK, 'pim_sync.test_connection'];
        
    } catch (Exception $e) {
        fn_pim_sync_log('Test connection error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Критическая ошибка: ' . $e->getMessage());
        return [CONTROLLER_STATUS_REDIRECT, 'pim_sync.manage'];
    }
}

/**
 * Обработка запроса на полную синхронизацию
 */
function fn_pim_sync_process_sync_full()
{
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
        
        // Получаем сервис синхронизации
        $company_id = fn_get_runtime_company_id();
        $sync_service = fn_pim_sync_get_sync_service($company_id);
        
        // Запускаем полную синхронизацию для каталога
        $result = $sync_service->syncCatalog($catalog_id, 'full');
        
        if ($result['status'] === 'completed') {
            $categories_count = $result['categories']['total'];
            $products_count = $result['products']['total'];
            
            fn_pim_sync_log("Полная синхронизация завершена успешно", 'info');
            fn_pim_sync_log("Категорий обработано: $categories_count", 'info');
            fn_pim_sync_log("Товаров обработано: $products_count", 'info');
            
            fn_set_notification('N', __('notice'), "Полная синхронизация завершена: $categories_count категорий, $products_count товаров");
        } else {
            $error_msg = isset($result['error']) ? $result['error'] : 'Неизвестная ошибка синхронизации';
            fn_pim_sync_log('Полная синхронизация завершилась с ошибкой: ' . $error_msg, 'error');
            fn_set_notification('E', __('error'), 'Ошибка синхронизации: ' . $error_msg);
        }
        
        fn_pim_sync_log('=== КОНЕЦ ПОЛНОЙ СИНХРОНИЗАЦИИ ===', 'info');
        
    } catch (Exception $e) {
        fn_pim_sync_log('Критическая ошибка полной синхронизации: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Критическая ошибка синхронизации: ' . $e->getMessage());
    }
    
    return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
}

/**
 * Обработка запроса на дельта синхронизацию
 */
function fn_pim_sync_process_sync_delta()
{
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
        
        // Получаем сервис синхронизации
        $company_id = fn_get_runtime_company_id();
        $sync_service = fn_pim_sync_get_sync_service($company_id);
        
        // Запускаем дельта синхронизацию для каталога
        $result = $sync_service->syncCatalog($catalog_id, 'delta', $days);
        
        if ($result['status'] === 'completed') {
            $categories_count = $result['categories']['total'];
            $products_count = $result['products']['total'];
            
            fn_pim_sync_log("Дельта синхронизация завершена успешно", 'info');
            fn_pim_sync_log("Категорий обработано: $categories_count", 'info');
            fn_pim_sync_log("Товаров обработано: $products_count", 'info');
            
            fn_set_notification('N', __('notice'), "Дельта синхронизация завершена: $categories_count категорий, $products_count товаров");
        } else {
            $error_msg = isset($result['error']) ? $result['error'] : 'Неизвестная ошибка синхронизации';
            fn_pim_sync_log('Дельта синхронизация завершилась с ошибкой: ' . $error_msg, 'error');
            fn_set_notification('E', __('error'), 'Ошибка дельта синхронизации: ' . $error_msg);
        }
        
        fn_pim_sync_log('=== КОНЕЦ ДЕЛЬТА СИНХРОНИЗАЦИИ ===', 'info');
        
    } catch (Exception $e) {
        fn_pim_sync_log('Критическая ошибка дельта синхронизации: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Критическая ошибка дельта синхронизации: ' . $e->getMessage());
    }
    
    return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
}

/**
 * Обработка запроса на очистку логов
 */
function fn_pim_sync_process_clear_logs()
{
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    
    try {
        if ($action == 'clear_all') {
            // Очищаем файл логов
            $result = fn_pim_sync_clear_logs();
            if ($result) {
                fn_set_notification('N', __('notice'), __('pim_sync.logs_cleared'));
                fn_pim_sync_log('Log file cleared successfully', 'info');
            } else {
                fn_set_notification('E', __('error'), __('pim_sync.logs_clear_failed'));
            }
        }
    } catch (Exception $e) {
        fn_pim_sync_log('Clear logs error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Ошибка очистки логов: ' . $e->getMessage());
    }
    
    // Добавляем параметр для принудительного обновления страницы
    $suffix = '?' . uniqid();
    return [CONTROLLER_STATUS_OK, 'pim_sync.clear_logs' . $suffix];
}
