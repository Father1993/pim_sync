<?php
/**
 * Файл с функциями логирования (адаптер для OOP Logger)
 *
 * @package Tygh\Addons\PimSync
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */

if (!defined('BOOTSTRAP')) {
  die('Access denied');
}

use Tygh\Addons\PimSync\Utils\Logger;

/**
 * Возвращает экземпляр логгера (реализация шаблона Singleton)
 * 
 * @return \Tygh\Addons\PimSync\Utils\LoggerInterface
 */
function fn_pim_sync_get_logger()
{
    static $logger = null;
    
    if ($logger === null) {
        $logger = new Logger();
    }
    
    return $logger;
}

/**
 * Логирование сообщения
 * @param string $message
 * @param string $level
 */
function fn_pim_sync_log($message, $level = 'info')
{
    fn_pim_sync_get_logger()->log($message, $level);
}

/**
 * Получает последние записи логов
 * 
 * @param int $limit
 * @param string|null $level
 * @return array
 */
function fn_pim_sync_get_recent_logs($limit = 50, $level = null)
{
    return fn_pim_sync_get_logger()->getRecentLogs($limit, $level);
}

/**
 * Очищает файл логов PIM Sync
 * 
 * @return bool
 */
function fn_pim_sync_clear_logs()
{
    return fn_pim_sync_get_logger()->clearLogs();
}
