<?php
/**
 * PIM Sync
 *
 * @file: Controller for addon → cs-cart
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

use Tygh\Registry;
use Tygh\Addons\PimSync\Api\PimApiClient;
use Tygh\Addons\PimSync\Exception\ApiAuthException;

$mode = !empty($_REQUEST['mode']) ? $_REQUEST['mode'] : 'manage';

// Обработка POST запросов
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once __DIR__ . '/pim_sync_post.php';
    exit;
}

// Определение действия и вызов соответствующего обработчика
switch ($mode) {
    case 'test_connection':
        fn_pim_sync_controller_test_connection();
        break;
        
    case 'sync_full':
        fn_pim_sync_controller_sync_full();
        break;
        
    case 'sync_delta':
        fn_pim_sync_controller_sync_delta();
        break;
        
    case 'clear_logs':
        fn_pim_sync_controller_clear_logs();
        break;
        
    case 'manage':
    default:
        fn_pim_sync_controller_manage();
        break;
}

/**
 * Обработчик страницы тестирования соединения
 */
function fn_pim_sync_controller_test_connection()
{
    fn_pim_sync_log('Отображение страницы тестирования', 'info');
    
    // Получаем текущие настройки для отображения
    $settings = fn_pim_sync_get_settings();
    $api_settings = [
        'api_url' => $settings['api_url'],
        'api_login' => $settings['api_login'],
        'catalog_id' => $settings['catalog_id']
    ];
    
    // Получаем последние ошибки из лог-файла
    $recent_errors = fn_pim_sync_get_recent_logs(50, 'error');
    
    Registry::get('view')->assign([
        'api_settings' => $api_settings,
        'recent_errors' => $recent_errors
    ]);
}

/**
 * Обработчик страницы управления
 */
function fn_pim_sync_controller_manage()
{
    // Получаем реальные данные из БД
    $company_id = fn_get_runtime_company_id();
    
    // Статистика из БД CS-Cart
    $stats = [
        'total_categories' => db_get_field("SELECT COUNT(*) FROM ?:categories WHERE company_id = ?i", $company_id),
        'total_products' => db_get_field("SELECT COUNT(*) FROM ?:products WHERE company_id = ?i", $company_id),
        'sync_errors' => 0 // Получаем из логов вместо БД
    ];
    
    // Получаем последние логи
    $recent_logs = fn_pim_sync_get_recent_logs(10);
    
    Registry::get('view')->assign([
        'pim_stats' => $stats,
        'recent_logs' => $recent_logs
    ]);
}

/**
 * Обработчик страницы очистки логов
 */
function fn_pim_sync_controller_clear_logs()
{
    try {
        // Получаем содержимое файла логов
        $log_file = Registry::get('config.dir.var') . 'pim_sync.log';
        $log_content = '';
        $log_file_exists = file_exists($log_file);
        $log_file_size = $log_file_exists ? filesize($log_file) : 0;
        
        if ($log_file_exists) {
            $log_content = file_get_contents($log_file);
        }
        
        // Передаем информацию о логах в шаблон
        Registry::get('view')->assign([
            'log_file_info' => [
                'path' => $log_file,
                'exists' => $log_file_exists,
                'size' => $log_file_size
            ],
            'log_content' => $log_content,
            'recent_logs' => fn_pim_sync_get_recent_logs(20)
        ]);
        
    } catch (Exception $e) {
        fn_pim_sync_log('Clear logs page error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Ошибка при загрузке страницы логов: ' . $e->getMessage());
    }
}

/**
 * Обработчик страницы полной синхронизации
 */
function fn_pim_sync_controller_sync_full()
{
    try {
        $company_id = fn_get_runtime_company_id();
        
        // Получаем статистику из CS-Cart
        $stats = [
            'categories' => [
                'total' => (int)db_get_field("SELECT COUNT(*) FROM ?:categories WHERE company_id = ?i", $company_id),
                'synced' => 0
            ],
            'products' => [
                'total' => (int)db_get_field("SELECT COUNT(*) FROM ?:products WHERE company_id = ?i", $company_id),
                'synced' => 0
            ],
            'last_sync' => null
        ];
        
        // Получаем последние логи
        $recent_logs = fn_pim_sync_get_recent_logs(10);
        
        Registry::get('view')->assign([
            'pim_stats' => $stats,
            'recent_logs' => $recent_logs
        ]);
        
    } catch (Exception $e) {
        fn_pim_sync_log('Sync full page error: ' . $e->getMessage(), 'error');
        fn_set_notification('E', __('error'), 'Ошибка загрузки страницы синхронизации: ' . $e->getMessage());
    }
}

/**
 * Обработчик страницы дельта-синхронизации
 */
function fn_pim_sync_controller_sync_delta()
{
    fn_pim_sync_controller_sync_full(); // Используем ту же логику
}
