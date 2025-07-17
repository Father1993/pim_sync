<?php

/**
* @file: func.php
* @description: Main features of PIM Sync addon
* @dependencies: CS-Cart core
* @created: 2025-06-27
*/

if (! defined('BOOTSTRAP')) {
    die('Access denied');
}

// Подключаем файл с функциями логирования
require_once(dirname(__FILE__) . '/logger.php');

use Tygh\Addons\PimSync\PimApiClient;
use Tygh\Registry;

/**
 * Install hook for PIM Sync addon
 */
function fn_pim_sync_install()
{
    fn_pim_sync_log('PIM Sync addon installed successfully');
    return true;
}

/**
 * Uninstall hook for PIM Sync addon
 */
function fn_pim_sync_uninstall()
{
    fn_pim_sync_log('PIM Sync addon uninstalled');
    return true;
}

/**
* Get PIM connection settings
* @return array
*/
function fn_pim_sync_get_settings()
{
    // Получаем настройки через правильный метод CS-Cart
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
    
    // Информация о последней синхронизации
    $company_id = fn_get_runtime_company_id();
    $last_log = fn_pim_sync_get_log_entries(1, $company_id);
    if (!empty($last_log)) {
        $log = $last_log[0];
        $status_html .= '<div class="alert alert-info">';
        $status_html .= '<strong>' . __('pim_sync.last_sync_info') . '</strong><br>';
        $sync_type_translation = __('pim_sync.sync_type_' . $log['sync_type']);
        if ($sync_type_translation === 'pim_sync.sync_type_' . $log['sync_type']) {
            // Если перевод не найден, используем оригинальное значение
            $sync_type_translation = $log['sync_type'];
        }
        $status_html .= __('pim_sync.sync_type') . ': ' . $sync_type_translation . '<br>';
        $status_html .= __('pim_sync.started_at') . ': ' . fn_date_format(strtotime($log['started_at']) ?: TIME, Registry::get('settings.Appearance.date_format') . ' ' . Registry::get('settings.Appearance.time_format')) . '<br>';
        $status_html .= __('pim_sync.status') . ': ' . __('pim_sync.status_' . $log['status']);
        if ($log['status'] === 'completed') {
            $status_html .= '<br>' . __('pim_sync.affected_categories') . ': ' . (int)$log['affected_categories'];
            $status_html .= '<br>' . __('pim_sync.affected_products') . ': ' . (int)$log['affected_products'];
        }
        $status_html .= '</div>';
    } else {
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
 * @return \Tygh\Addons\PimSync\PimSyncService
 */
function fn_pim_sync_get_sync_service($company_id = 0)
{
    // Получаем настройки
    $settings = fn_pim_sync_get_settings();
    
    // Инициализируем клиент PIM API
    $pim_client = new \Tygh\Addons\PimSync\PimApiClient(
        $settings['api_url'],
        $settings['api_login'],
        $settings['api_password']
    );
    
    // Инициализируем клиент CS-Cart API
    $cs_cart_client = new \Tygh\Addons\PimSync\CsCartApiClient(
        $settings['cs_cart_api_url'],
        $settings['cs_cart_email'],
        $settings['cs_cart_api_key']
    );
    
    // Создаем и возвращаем сервис синхронизации
    return new \Tygh\Addons\PimSync\PimSyncService($pim_client, $cs_cart_client, $company_id);
}
