<?php
/**
 * @file: pim_sync_post.php
 * @description: Обработчики POST запросов для контроллера PIM Sync
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// Добавляем отладочную информацию для выявления проблемы
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Защитное логирование для отслеживания проблемы
if (function_exists('fn_log_event')) {
    fn_log_event('pim_sync', 'debug', ['message' => 'Запуск pim_sync_post.php']);
}

use Tygh\Registry;
use Tygh\Addons\PimSync\Api\PimApiClient;
use Tygh\Addons\PimSync\Exception\ApiAuthException;

// Валидация базовых параметров
if (!defined('AREA') || AREA !== 'A') {
    die('Access denied: Admin area only');
}

// Проверка прав доступа
if (!fn_check_permissions('pim_sync', 'pim_sync_post', 'admin')) {
    die('Access denied: Insufficient permissions');
}

// CS-Cart автоматически проверяет CSRF токены через fn.control.php
// Дополнительная проверка не требуется

/**
 * Валидация входных данных
 */
function fn_pim_sync_validate_input($required_fields = [], $optional_fields = [])
{
    $validated_data = [];
    
    // Проверка обязательных полей
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            fn_set_notification('E', __('error'), __('pim_sync.required_field_missing', ['field' => $field]));
            return false;
        }
        $validated_data[$field] = trim($_POST[$field]);
    }
    
    // Проверка необязательных полей
    foreach ($optional_fields as $field) {
        if (isset($_POST[$field])) {
            $validated_data[$field] = trim($_POST[$field]);
        }
    }
    
    // Защита от XSS
    foreach ($validated_data as $key => $value) {
        $validated_data[$key] = strip_tags($value);
    }
    
    return $validated_data;
}

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
    // Валидация входных данных
    $validated_data = fn_pim_sync_validate_input();
    if ($validated_data === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'pim_sync.manage'];
    }
    
    try {
        // Логируем начало процесса тестирования
        if (function_exists('fn_log_event')) {
            fn_log_event('pim_sync', 'debug', ['message' => 'Начало функции тестирования соединения']);
        }
        
        // Добавляем пустую строку перед началом
        fn_pim_sync_log_empty_line();
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
                // Проверяем существование класса для более информативной ошибки
                if (!class_exists('Tygh\Addons\PimSync\Api\PimApiClient')) {
                    fn_log_event('pim_sync', 'error', ['message' => 'Класс PimApiClient не найден, проблема с автозагрузкой']);
                    throw new Exception('Класс PimApiClient не найден. Проблема с автозагрузкой классов.');
                }
                
                // ОТЛАДКА: Пропускаем реальный API запрос при тестировании
                if (isset($_REQUEST['debug_skip_api']) && $_REQUEST['debug_skip_api'] == 'Y') {
                    $test_result['success'] = true;
                    $test_result['message'] = 'Отладочный режим: API запрос пропущен';
                    $test_result['details'][] = 'Это тестовый ответ без реального обращения к API';
                    fn_set_notification('N', __('notice'), 'Отладочный режим: соединение не проверялось');
                    fn_log_event('pim_sync', 'debug', ['message' => 'Пропущен API запрос в режиме отладки']);
                } else {
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
                }
            } catch (ApiAuthException $e) {
                fn_log_event('pim_sync', 'error', ['message' => 'ApiAuthException: ' . $e->getMessage()]);
                $test_result['message'] = 'Ошибка авторизации в API';
                $test_result['error'] = $e->getMessage();
                fn_pim_sync_log('API authentication error: ' . $e->getMessage(), 'error');
                fn_set_notification('E', __('error'), 'Ошибка авторизации: ' . $e->getMessage());
            } catch (Exception $e) {
                fn_log_event('pim_sync', 'error', ['message' => 'Exception: ' . $e->getMessage()]);
                $test_result['message'] = 'Критическая ошибка при подключении';
                $test_result['error'] = $e->getMessage();
                fn_pim_sync_log('Critical connection error: ' . $e->getMessage(), 'error');
                fn_set_notification('E', __('error'), 'Ошибка: ' . $e->getMessage());
            }
        }
        
        // Передаем результат в view для отображения
        Registry::get('view')->assign([
            'test_result' => $test_result,
            'api_settings' => [
                'api_url' => $api_url,
                'api_login' => $api_login,
                'catalog_id' => $catalog_id
            ]
        ]);
        
        fn_pim_sync_log('=== КОНЕЦ ТЕСТИРОВАНИЯ СОЕДИНЕНИЯ ===', 'info');
        // Добавляем пустую строку после завершения
        fn_pim_sync_log_empty_line();
        
    } catch (Exception $e) {
        fn_pim_sync_log('Test connection error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Критическая ошибка: ' . $e->getMessage());
    }
}

/**
 * Обработка запроса на полную синхронизацию
 */
function fn_pim_sync_process_sync_full()
{
    // Валидация входных данных
    $validated_data = fn_pim_sync_validate_input();
    if ($validated_data === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'pim_sync.manage'];
    }
    
    try {
        fn_pim_sync_log_empty_line();
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
        fn_pim_sync_log_empty_line();
        
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
    // Валидация входных данных
    $validated_data = fn_pim_sync_validate_input([], ['days']);
    if ($validated_data === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'pim_sync.manage'];
    }
    
    try {
        $days = isset($validated_data['days']) ? (int)$validated_data['days'] : 1;
        // Дополнительная валидация параметра days
        if ($days < 1 || $days > 365) {
            fn_set_notification('E', __('error'), 'Неверное значение дней для дельта синхронизации (1-365)');
            return [CONTROLLER_STATUS_REDIRECT, 'pim_sync.manage'];
        }
        
        fn_pim_sync_log_empty_line();
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
        fn_pim_sync_log_empty_line();
        
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
    // Валидация входных данных
    $validated_data = fn_pim_sync_validate_input([], ['action']);
    if ($validated_data === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'pim_sync.manage'];
    }
    
    $action = isset($validated_data['action']) ? $validated_data['action'] : '';
    
    try {
        if ($action == 'clear_all') {
            // Добавляем лог о попытке очистки
            fn_pim_sync_log_empty_line();
            fn_pim_sync_log('Запрос на очистку лог-файла', 'info');
            
            // Очищаем файл логов
            $result = fn_pim_sync_clear_logs();
            
            // После очистки логов сразу записываем сообщение об успешной очистке
            // чтобы лог не был пустым
            fn_pim_sync_log('Лог-файл был очищен', 'info');
            fn_pim_sync_log_empty_line();
            
            // Всегда показываем успешное уведомление, так как файл был перезаписан
            fn_set_notification('N', __('notice'), __('pim_sync.logs_cleared'));
        }
    } catch (Exception $e) {
        fn_pim_sync_log('Clear logs error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Ошибка очистки логов: ' . $e->getMessage());
    }
    
    // Добавляем параметр для принудительного обновления страницы
    $suffix = '?' . uniqid();
    return [CONTROLLER_STATUS_OK, 'pim_sync.clear_logs' . $suffix];
}

/**
 * Обработка запроса на синхронизацию дерева категорий
 */
function fn_pim_sync_process_sync_category_tree()
{
    // Валидация входных данных
    $validated_data = fn_pim_sync_validate_input(['catalog_id', 'company_id'], ['storefront_id']);
    if ($validated_data === false) {
        return [CONTROLLER_STATUS_REDIRECT, 'pim_sync.manage'];
    }
    
    try {
        $catalog_id = $validated_data['catalog_id'];
        $company_id = (int)$validated_data['company_id'];
        $storefront_id = isset($validated_data['storefront_id']) ? (int)$validated_data['storefront_id'] : 1;
        
        fn_pim_sync_log_empty_line();
        fn_pim_sync_log("=== НАЧАЛО СИНХРОНИЗАЦИИ ДЕРЕВА КАТЕГОРИЙ ===", 'info');
        fn_pim_sync_log("Каталог: $catalog_id, Компания: $company_id, Витрина: $storefront_id", 'info');
        
        // Получаем сервис синхронизации для указанной компании
        $sync_service = fn_pim_sync_get_sync_service($company_id, $storefront_id);
        
        if (!$sync_service) {
            throw new Exception('Не удалось инициализировать сервис синхронизации');
        }
        
        // Выполняем синхронизацию категорий
        $result = $sync_service->syncCategories($catalog_id, $company_id, $storefront_id);
        
        if ($result && !isset($result['error'])) {
            $created = $result['created'] ?? 0;
            $updated = $result['updated'] ?? 0;
            $failed = $result['failed'] ?? 0;
            $total = $result['total'] ?? 0;
            
            fn_pim_sync_log("Синхронизация категорий завершена. Всего: $total, Создано: $created, Обновлено: $updated, Ошибок: $failed", 'info');
            
            if ($failed > 0) {
                fn_set_notification('W', __('warning'), "Синхронизация завершена с ошибками. Создано: $created, Обновлено: $updated, Ошибок: $failed");
            } else {
                fn_set_notification('N', __('notice'), "Синхронизация категорий успешно завершена. Всего обработано: $total, Создано: $created, Обновлено: $updated");
            }
            
            // Сохраняем детали в лог
            if (!empty($result['details'])) {
                foreach ($result['details'] as $detail) {
                    $action = $detail['action'] ?? 'unknown';
                    $pim_header = $detail['pim_header'] ?? 'Unknown';
                    $cscart_id = $detail['cscart_id'] ?? 'N/A';
                    fn_pim_sync_log("  - $action: $pim_header (CS-Cart ID: $cscart_id)", 'debug');
                }
            }
        } else {
            $error_msg = isset($result['error']) ? $result['error'] : 'Неизвестная ошибка синхронизации';
            fn_pim_sync_log('Синхронизация категорий завершилась с ошибкой: ' . $error_msg, 'error');
            fn_set_notification('E', __('error'), 'Ошибка синхронизации категорий: ' . $error_msg);
        }
        
        fn_pim_sync_log('=== КОНЕЦ СИНХРОНИЗАЦИИ ДЕРЕВА КАТЕГОРИЙ ===', 'info');
        fn_pim_sync_log_empty_line();
        
    } catch (Exception $e) {
        fn_pim_sync_log('Критическая ошибка синхронизации категорий: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Критическая ошибка: ' . $e->getMessage());
    }
    
    return [CONTROLLER_STATUS_OK, 'pim_sync.manage'];
}
