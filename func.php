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
    // Создаем таблицы для маппинга категорий и продуктов
    
    // Таблица для маппинга категорий PIM <-> CS-Cart
    $sql = "CREATE TABLE IF NOT EXISTS ?:pim_sync_category_map (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pim_id` int(11) NOT NULL COMMENT 'ID категории в PIM',
        `pim_sync_uid` varchar(255) NOT NULL COMMENT 'syncUid категории из PIM',
        `cscart_category_id` int(11) NOT NULL COMMENT 'ID категории в CS-Cart',
        `catalog_id` varchar(255) NOT NULL COMMENT 'ID каталога в PIM',
        `company_id` int(11) NOT NULL DEFAULT '0' COMMENT 'ID компании в CS-Cart',
        `storefront_id` int(11) NOT NULL DEFAULT '0' COMMENT 'ID витрины в CS-Cart',
        `timestamp` int(11) NOT NULL DEFAULT '0' COMMENT 'Время последней синхронизации',
        PRIMARY KEY (`id`),
        UNIQUE KEY `pim_sync_uid` (`pim_sync_uid`, `catalog_id`, `company_id`, `storefront_id`),
        KEY `pim_id` (`pim_id`),
        KEY `cscart_category_id` (`cscart_category_id`),
        KEY `catalog_id` (`catalog_id`),
        KEY `company_id` (`company_id`),
        KEY `storefront_id` (`storefront_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Маппинг категорий PIM <-> CS-Cart';";
    
    db_query($sql);
    
    // Таблица для маппинга продуктов PIM <-> CS-Cart
    $sql = "CREATE TABLE IF NOT EXISTS ?:pim_sync_product_map (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `pim_id` int(11) NOT NULL COMMENT 'ID продукта в PIM',
        `pim_sync_uid` varchar(255) NOT NULL COMMENT 'syncUid продукта из PIM',
        `cscart_product_id` int(11) NOT NULL COMMENT 'ID продукта в CS-Cart',
        `catalog_id` varchar(255) NOT NULL COMMENT 'ID каталога в PIM',
        `company_id` int(11) NOT NULL DEFAULT '0' COMMENT 'ID компании в CS-Cart',
        `storefront_id` int(11) NOT NULL DEFAULT '0' COMMENT 'ID витрины в CS-Cart',
        `timestamp` int(11) NOT NULL DEFAULT '0' COMMENT 'Время последней синхронизации',
        PRIMARY KEY (`id`),
        UNIQUE KEY `pim_sync_uid` (`pim_sync_uid`, `catalog_id`, `company_id`, `storefront_id`),
        KEY `pim_id` (`pim_id`),
        KEY `cscart_product_id` (`cscart_product_id`),
        KEY `catalog_id` (`catalog_id`),
        KEY `company_id` (`company_id`),
        KEY `storefront_id` (`storefront_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Маппинг продуктов PIM <-> CS-Cart';";
    
    db_query($sql);
    
    fn_pim_sync_log('PIM Sync addon installed successfully with database tables', 'info');
    return true;
}

/**
 * Uninstall hook for PIM Sync addon
 */
function fn_pim_sync_uninstall()
{
    // Удаляем таблицы аддона
    db_query("DROP TABLE IF EXISTS ?:pim_sync_category_map");
    db_query("DROP TABLE IF EXISTS ?:pim_sync_product_map");
    
    // Прямая запись в системный лог CS-Cart без использования классов аддона
    fn_log_event('pim_sync', 'info', ['message' => 'PIM Sync addon uninstalled with database tables removed']);
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
    $storefront_id = Registry::get('runtime.storefront_id') ?: 1;
    return new Tygh\Addons\PimSync\PimSyncService($pim_client, $cs_cart_client, $company_id, $storefront_id);
}

/**
 * Получает список доступных витрин/компаний
 *
 * @return array
 */
function fn_pim_sync_get_available_storefronts()
{
    $storefronts = [];
    
    try {
        // Пробуем получить через API
        $cs_cart_client = fn_pim_sync_get_cs_cart_client();
        if ($cs_cart_client) {
            $response = $cs_cart_client->get('/api/2.0/vendors');
            
            if (!empty($response['vendors'])) {
                foreach ($response['vendors'] as $vendor) {
                    if ($vendor['status'] === 'A') { // Только активные
                        $storefronts[] = [
                            'company_id' => $vendor['company_id'],
                            'name' => $vendor['company'],
                            'email' => $vendor['email'] ?? '',
                            'seo_name' => $vendor['seo_name'] ?? ''
                        ];
                    }
                }
                
                if (!empty($storefronts)) {
                    return $storefronts;
                }
            }
        }
    } catch (Exception $e) {
        fn_pim_sync_log('Ошибка получения списка витрин через API: ' . $e->getMessage(), 'warning');
    }
    
    // Если API не работает, пробуем получить напрямую из БД
    try {
        $companies = db_get_array("SELECT company_id, company, email, status FROM ?:companies WHERE status = ?s", 'A');
        
        if (!empty($companies)) {
            foreach ($companies as $company) {
                $storefronts[] = [
                    'company_id' => $company['company_id'],
                    'name' => $company['company'],
                    'email' => $company['email'] ?? '',
                    'seo_name' => ''
                ];
            }
            fn_pim_sync_log('Витрины загружены из БД: ' . count($storefronts), 'info');
        }
    } catch (Exception $e) {
        fn_pim_sync_log('Ошибка получения витрин из БД: ' . $e->getMessage(), 'error');
    }
    
    return $storefronts;
}

/**
 * Получить клиент CS-Cart API
 * 
 * @return CsCartApiClient|null
 */
function fn_pim_sync_get_cs_cart_client()
{
    try {
        $settings = fn_pim_sync_get_settings();
        $logger = fn_pim_sync_get_logger();
        
        $cs_cart_client = new CsCartApiClient(
            $settings['cs_cart_api_url'] ?: fn_url('', 'A', 'http'),
            $settings['cs_cart_email'],
            $settings['cs_cart_api_key'],
            $logger
        );
        
        return $cs_cart_client;
        
    } catch (Exception $e) {
        fn_pim_sync_log('Ошибка создания CS-Cart API клиента: ' . $e->getMessage(), 'error');
        return null;
    }
}

/**
 * Получить клиент PIM API
 * 
 * @return PimApiClient|null
 */
function fn_pim_sync_get_pim_client()
{
    try {
        $settings = fn_pim_sync_get_settings();
        $logger = fn_pim_sync_get_logger();
        
        $pim_client = new PimApiClient(
            $settings['api_url'],
            $settings['api_login'],
            $settings['api_password'],
            $logger
        );
        
        return $pim_client;
        
    } catch (Exception $e) {
        fn_pim_sync_log('Ошибка создания PIM API клиента: ' . $e->getMessage(), 'error');
        return null;
    }
}
