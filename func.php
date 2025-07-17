<?php
/**
 * PIM Sync - основные функции аддона
 *
 * @package Tygh\Addons\PimSync
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// Подключаем файл с функциями логирования
require_once(dirname(__FILE__) . '/logger.php');

use Tygh\Addons\PimSync\Api\PimApiClient;
use Tygh\Addons\PimSync\Api\CsCartApiClient;
use Tygh\Addons\PimSync\PimSyncService;
use Tygh\Registry;

/**
 * Install hook for PIM Sync addon
 */
function fn_pim_sync_install()
{
    fn_pim_sync_log('PIM Sync addon installed successfully', 'info');
    return true;
}

/**
 * Uninstall hook for PIM Sync addon
 */
function fn_pim_sync_uninstall()
{
    // Прямая запись в системный лог CS-Cart без использования классов аддона
    fn_log_event('pim_sync', 'info', ['message' => 'PIM Sync addon uninstalled']);
    return true;
}

/**
 * Получает настройки аддона PIM Sync
 * 
 * @return array Массив с настройками
 */
function fn_pim_sync_get_settings()
{
    // Получаем настройки через Registry
    $addon_settings = Registry::get('addons.pim_sync');
    
    return [
        'api_url' => $addon_settings['api_url'] ?? '',
        'api_login' => $addon_settings['api_login'] ?? '',
        'api_password' => $addon_settings['api_password'] ?? '',
        'catalog_id' => $addon_settings['catalog_id'] ?? '',
        'sync_enabled' => ($addon_settings['sync_enabled'] ?? 'N') === 'Y',
        'sync_interval' => (int)($addon_settings['sync_interval'] ?? 30),
        'default_sync_type' => $addon_settings['default_sync_type'] ?? 'delta',
        'enable_logging' => ($addon_settings['enable_logging'] ?? 'Y') === 'Y',
        'log_level' => $addon_settings['log_level'] ?? 'info',
        'api_timeout' => (int)($addon_settings['api_timeout'] ?? 30),
        // CS-Cart API настройки
        'cs_cart_api_url' => $addon_settings['cs_cart_api_url'] ?? '',
        'cs_cart_email' => $addon_settings['cs_cart_email'] ?? '',
        'cs_cart_api_key' => $addon_settings['cs_cart_api_key'] ?? '',
    ];
}

/**
 * Функция для отображения статуса соединения с PIM API
 * Используется в настройках аддона (тип info)
 * 
 * @return string HTML с информацией о статусе
 */
function fn_pim_sync_connection_status()
{
    $settings = fn_pim_sync_get_settings();
    $status_html = '';
    
    // Проверяем наличие базовых настроек
    if (empty($settings['api_url']) || empty($settings['api_login']) || empty($settings['api_password'])) {
        $status_html .= '<div class="alert alert-warning">';
        $status_html .= '<strong>' . __('pim_sync.connection_not_configured') . '</strong><br>';
        $status_html .= __('pim_sync.fill_api_settings');
        $status_html .= '</div>';
        return $status_html;
    }
    
    // Отображаем базовую информацию о настройках без выполнения API запросов
    $status_html .= '<div class="alert alert-info">';
    $status_html .= '<strong>' . __('pim_sync.connection_settings') . '</strong><br>';
    $status_html .= __('pim_sync.api_url') . ': ' . htmlspecialchars($settings['api_url']) . '<br>';
    $status_html .= __('pim_sync.login') . ': ' . htmlspecialchars($settings['api_login']);
    if (!empty($settings['catalog_id'])) {
        $status_html .= '<br>' . __('pim_sync.catalog_id') . ': ' . htmlspecialchars($settings['catalog_id']);
    }
    $status_html .= '</div>';
    
    // Добавляем кнопку для ручной проверки соединения
    $status_html .= '<div class="alert alert-info">';
    $status_html .= '<strong>' . __('pim_sync.connection_check') . '</strong><br>';
    $status_html .= __('pim_sync.connection_check_description') . '<br><br>';
    $status_html .= '<a href="' . fn_url('pim_sync.test_connection') . '" class="btn btn-primary">';
    $status_html .= __('pim_sync.test_connection_button');
    $status_html .= '</a>';
    $status_html .= '</div>';
    
    // Информация о последней синхронизации (получаем из логов)
    try {
        $recent_logs = fn_pim_sync_get_recent_logs(10);
        $sync_logs = array_filter($recent_logs, function($log) {
            return strpos($log['message'], 'НАЧАЛО') !== false && 
                (strpos($log['message'], 'СИНХРОНИЗАЦИИ') !== false);
        });
        
        if (!empty($sync_logs)) {
            $last_log = reset($sync_logs);
            $status_html .= '<div class="alert alert-info">';
            $status_html .= '<strong>' . __('pim_sync.last_sync_info') . '</strong><br>';
            $status_html .= __('pim_sync.started_at') . ': ' . $last_log['timestamp'] . '<br>';
            
            // Определяем тип синхронизации по сообщению
            $sync_type = (strpos($last_log['message'], 'ПОЛНОЙ') !== false) ? 'full' : 'delta';
            $status_html .= __('pim_sync.sync_type') . ': ' . __('pim_sync.sync_type_' . $sync_type);
            
            $status_html .= '</div>';
        } else {
            $status_html .= '<div class="alert alert-info">';
            $status_html .= __('pim_sync.no_sync_history');
            $status_html .= '</div>';
        }
    } catch (Exception $e) {
        // В случае ошибки с получением логов просто не показываем историю
        $status_html .= '<div class="alert alert-info">';
        $status_html .= __('pim_sync.no_sync_history');
        $status_html .= '</div>';
    }
    
    return $status_html;
}

/**
 * Получить сервис синхронизации PIM
 * 
 * @param int $company_id ID компании в CS-Cart
 * @return object Сервис синхронизации
 */
function fn_pim_sync_get_sync_service($company_id = 0)
{
    // Получаем настройки
    $settings = fn_pim_sync_get_settings();
    
    // Получаем экземпляр логгера
    $logger = fn_pim_sync_get_logger();
    
    // Инициализируем клиент PIM API
    $pim_client = new PimApiClient(
        $settings['api_url'],
        $settings['api_login'],
        $settings['api_password'],
        $logger
    );
    
    // Инициализируем клиент CS-Cart API
    $cs_cart_client = new CsCartApiClient(
        $settings['cs_cart_api_url'] ?: fn_url('', 'A', 'http'),
        $settings['cs_cart_email'],
        $settings['cs_cart_api_key'],
        $logger
    );
    
    // Создаем и возвращаем сервис синхронизации
    // Примечание: Класс PimSyncService должен быть создан отдельно
    return new Tygh\Addons\PimSync\PimSyncService($pim_client, $cs_cart_client, $company_id);
}
