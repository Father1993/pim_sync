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

// Явное подключение интерфейса
require_once __DIR__ . '/classes/Utils/LoggerInterface.php';

use Tygh\Addons\PimSync\Utils\Logger;
use Tygh\Addons\PimSync\Utils\LoggerInterface;

/**
 * Возвращает экземпляр логгера (реализация шаблона Singleton)
 * 
 * @return \Tygh\Addons\PimSync\Utils\LoggerInterface
 */
function fn_pim_sync_get_logger()
{
    static $logger = null;
    
    if ($logger === null) {
        // Проверяем существование класса Logger перед использованием
        if (class_exists('\Tygh\Addons\PimSync\Utils\Logger')) {
            $logger = new \Tygh\Addons\PimSync\Utils\Logger();
        } else {
            // Fallback для случаев, когда класс Logger недоступен (например, при удалении аддона)
            // или во время инициализации настроек
            $logger = new class() implements \Tygh\Addons\PimSync\Utils\LoggerInterface {
                public function log(string $message, string $level = 'info'): void {
                    if ($level === 'error' || $level === 'critical') {
                        fn_log_event('pim_sync', $level, ['message' => $message]);
                    }
                }
                public function getRecentLogs(int $limit = 50, ?string $level = null): array { 
                    return []; 
                }
                public function clearLogs(): bool { 
                    return true; 
                }
            };
        }
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

/**
 * Добавляет пустую строку в файл лога для улучшения читаемости
 * 
 * @return void
 */
function fn_pim_sync_log_empty_line()
{
    fn_pim_sync_get_logger()->addEmptyLine();
}
