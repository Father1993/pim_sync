<?php
/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync\Utils
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */

namespace Tygh\Addons\PimSync\Utils;
use Tygh\Registry;

class Logger implements LoggerInterface
{
    private string $logFile;
    
    /**
     * Конструктор
     *
     * @param string|null $logFile Путь к файлу логов (если null, используется стандартный)
     */
    public function __construct(?string $logFile = null)
    {
        $this->logFile = $logFile ?? Registry::get('config.dir.var') . 'pim_sync.log';
    }
    
    /**
     * {@inheritDoc}
     */
    public function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
        file_put_contents($this->logFile, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Записываем критические ошибки в системный лог CS-Cart
        if ($level === 'error' || $level === 'critical') {
            fn_log_event('pim_sync', $level, ['message' => $message]);
        }
    }
    
    /**
     * {@inheritDoc}
     */
    public function createLogEntry(string $sync_type = 'manual', int $company_id = 0): int
    {
        $data = [
            'sync_type' => $sync_type,
            'started_at' => date('Y-m-d H:i:s'),
            'status' => 'running',
            'company_id' => $company_id,
        ];
        
        db_query('INSERT INTO ?:pim_sync_log ?e', $data);
        return (int)db_get_field("SELECT LAST_INSERT_ID()");
    }
    
    /**
     * {@inheritDoc}
     */
    public function updateLogEntry(int $log_id, array $data): void
    {
        db_query('UPDATE ?:pim_sync_log SET ?u WHERE log_id = ?i', $data, $log_id);
    }
    
    /**
     * {@inheritDoc}
     */
    public function getLogEntries(int $limit = 10, int $company_id = 0): array
    {
    $logs = db_get_array(
    "SELECT * FROM ?:pim_sync_log WHERE company_id = ?i ORDER BY started_at DESC LIMIT ?i",
    $company_id,
    $limit
    );

    // Обработка файла логов
    foreach ($logs as &$log) {
    if (file_exists($this->logFile)) {
    $log_content = file_get_contents($this->logFile);
    if ($log_content) {
        $lines = array_filter(explode("\n", $log_content));
        $errors = [];
        $warnings = [];
        
        // Поиск записей соответствующих данному лог-событию
        // Ищем по временным меткам или другим маркерам
        foreach ($lines as $line) {
            // Ищем записи лога, соответствующие этой записи синхронизации
            $timestamp = strtotime($log['started_at']);
            $completed_timestamp = !empty($log['completed_at']) ? strtotime($log['completed_at']) : time();
            
            // Извлекаем временную метку из строки лога
            if (preg_match('/^\[([\d-\s:]+)\]/', $line, $matches)) {
                $log_time = strtotime($matches[1]);
                
                // Проверяем, попадает ли время в интервал синхронизации
                if ($log_time >= $timestamp && $log_time <= $completed_timestamp) {
                    if (strpos($line, "[error]") !== false) {
                        $errors[] = trim(str_replace("[error]", "", strstr($line, "[error]")));
                    }
                    if (strpos($line, "[warning]") !== false) {
                        $warnings[] = trim(str_replace("[warning]", "", strstr($line, "[warning]")));
                    }
                }
            }
        }
        
        // Добавляем найденные ошибки и предупреждения в запись лога
        $log['errors'] = $errors;
        $log['warnings'] = $warnings;
        
        if (!empty($errors) || !empty($warnings)) {
            $details = [];
            if (!empty($errors)) {
                $details[] = "Ошибки:\n" . implode("\n", $errors);
            }
            if (!empty($warnings)) {
                $details[] = "Предупреждения:\n" . implode("\n", $warnings);
            }
            $log['error_details'] = implode("\n\n", $details);
        }
        }
      }
    }
    
    return $logs;
    }

    public function clearLogFile(): bool
    {
        if (file_exists($this->logFile)) {
            return (bool)file_put_contents($this->logFile, '');
        }
        return false;
    }
}
