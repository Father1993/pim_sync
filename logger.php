<?php

/**
 * @file: logger.php
 * @description: Логирование для PIM Sync addon
 * @dependencies: CS-Cart core
 * @created: 2025-07-14
 */

if (!defined('BOOTSTRAP')) {
  die('Access denied');
}

use Tygh\Registry;

/**
* Logging of synchronization operations
* @param string $message
* @param string $level
*/
function fn_pim_sync_log($message, $level = 'info'): void
{
    $log_file = Registry::get('config.dir.var') . 'pim_sync.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    if ($level === 'error' || $level === 'critical') {
        fn_log_event('pim_sync', $level, ['message' => $message]);
    }
}

/**
* Create a sync log entry
* @param string $sync_type
* @param int $company_id
* @return int
*/
function fn_pim_sync_create_log_entry($sync_type = 'manual', $company_id = 0)
{
    $data = [
        'sync_type' => $sync_type,
        'started_at' => date('Y-m-d H:i:s'),
        'status' => 'running',
        'company_id' => $company_id,
    ];
    db_query('INSERT INTO ?:pim_sync_log ?e', $data);

    // Получаем ID последней вставленной записи
    return db_get_field("SELECT LAST_INSERT_ID()");
}

/**
* Update sync log entry
* @param int $log_id
* @param array $data
*/
function fn_pim_sync_update_log_entry($log_id, $data): void
{
    db_query('UPDATE ?:pim_sync_log SET ?u WHERE log_id = ?i', $data, $log_id);
}

/**
 * Получает записи логов из БД и добавляет информацию об ошибках из файла логов
 * 
 * @param int $limit Количество записей
 * @param int $company_id ID компании
 * @return array Массив записей логов
 */
function fn_pim_sync_get_log_entries($limit = 10, $company_id = 0) {
    $logs = db_get_array(
        "SELECT * FROM ?:pim_sync_log WHERE company_id = ?i ORDER BY started_at DESC LIMIT ?i",
        $company_id,
        $limit
    );
    
    // Добавляем ошибки и предупреждения из файла логов для каждой записи
    foreach ($logs as &$log) {
        $log_content = file_get_contents(fn_pim_sync_get_log_file_path());
        if ($log_content) {
            $lines = array_filter(explode("\n", $log_content));
            $errors = [];
            $warnings = [];
            
            // Ищем записи между началом и концом тестирования
            $is_current_test = false;
            foreach ($lines as $line) {
                if (strpos($line, "=== НАЧАЛО ТЕСТИРОВАНИЯ СОЕДИНЕНИЯ ===") !== false) {
                    $is_current_test = true;
                    continue;
                }
                
                if ($is_current_test) {
                    if (strpos($line, "[error]") !== false) {
                        $errors[] = trim(str_replace("[error]", "", strstr($line, "[error]")));
                    }
                    if (strpos($line, "[warning]") !== false) {
                        $warnings[] = trim(str_replace("[warning]", "", strstr($line, "[warning]")));
                    }
                }
                
                if (strpos($line, "=== КОНЕЦ ТЕСТИРОВАНИЯ СОЕДИНЕНИЯ ===") !== false) {
                    $is_current_test = false;
                }
            }
            
            // Добавляем найденные ошибки и предупреждения в запись лога
            $log['errors'] = $errors;
            $log['warnings'] = $warnings;
            
            // Если есть ошибки или предупреждения, добавляем их в детали
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
    
    return $logs;
}

/**
 * Возвращает путь к файлу логов
 * 
 * @return string Путь к файлу логов
 */
function fn_pim_sync_get_log_file_path() {
    return 'var/pim_sync.log';
}

/**
 * Очищает файл логов PIM Sync
 * 
 * @return bool Результат операции
 */
function fn_pim_sync_clear_log_file() {
    $log_file = fn_pim_sync_get_log_file_path();
    
    if (file_exists($log_file)) {
        // Очищаем содержимое файла
        if (file_put_contents($log_file, '') !== false) {
            fn_pim_sync_log('Log file cleared', 'info');
            return true;
        }
    }
    
    return false;
}
