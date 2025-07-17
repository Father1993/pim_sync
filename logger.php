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
 * @return \Tygh\Addons\PimSync\Utils\Logger
 */
function fn_pim_sync_get_logger(): Logger
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
function fn_pim_sync_log($message, $level = 'info'): void
{
    fn_pim_sync_get_logger()->log($message, $level);
}

/**
 * Create a sync log entry
 * @param string $sync_type
 * @param int $company_id
 * @return int
 */
function fn_pim_sync_create_log_entry($sync_type = 'manual', $company_id = 0)
{
    return fn_pim_sync_get_logger()->createLogEntry($sync_type, $company_id);
}

/**
 * Update sync log entry
 * @param int $log_id
 * @param array $data
 */
function fn_pim_sync_update_log_entry($log_id, $data): void
{
    fn_pim_sync_get_logger()->updateLogEntry($log_id, $data);
}

/**
 * Получает записи логов из БД и добавляет информацию об ошибках из файла логов
 * 
 * @param int $limit Количество записей
 * @param int $company_id ID компании
 * @return array Массив записей логов
 */
function fn_pim_sync_get_log_entries($limit = 10, $company_id = 0) {
    return fn_pim_sync_get_logger()->getLogEntries($limit, $company_id);
}

/**
 * Очищает файл логов PIM Sync
 * 
 * @return bool Результат операции
 */
function fn_pim_sync_clear_log_file() {
    return fn_pim_sync_get_logger()->clearLogFile();
}
