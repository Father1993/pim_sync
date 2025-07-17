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
     * @param string|null $logFile Путь к файлу лога
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
    
        // Дублируем критические ошибки в системный лог CS-Cart
        if ($level === 'error' || $level === 'critical') {
            fn_log_event('pim_sync', $level, ['message' => $message]);
        }
    }
    
    /**
     * {@inheritDoc}
     */
    public function getRecentLogs(int $limit = 50, ?string $level = null): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        $logs = [];
        $counter = 0;
        
        // Читаем файл с конца для получения последних записей
        $lines = $this->readLastLines($this->logFile, $limit * 2); // Берем с запасом для фильтрации
        
        foreach ($lines as $line) {
            if ($counter >= $limit) {
                break;
            }
            
            // Парсим строку лога
            if (preg_match('/^\[(.*?)\] \[(.*?)\] (.*)$/', $line, $matches)) {
                $timestamp = $matches[1];
                $entry_level = $matches[2];
                $message = $matches[3];
                
                // Фильтр по уровню, если задан
                if ($level !== null && $entry_level !== $level) {
                    continue;
                }
                
                $logs[] = [
                    'timestamp' => $timestamp,
                    'level' => $entry_level,
                    'message' => $message
                ];
                
                $counter++;
            }
        }
        
        return $logs;
    }
    
    /**
     * {@inheritDoc}
     */
    public function clearLogs(): bool
    {
        // Используем file_put_contents для очистки файла
        // Если файл не существует, функция создаст его
        file_put_contents($this->logFile, '');
        
        // Всегда возвращаем true, так как файл либо очищен, либо создан заново
        return true;
    }
    
    /**
     * Добавляет пустую строку в лог
     * 
     * @return void
     */
    public function addEmptyLine(): void
    {
        file_put_contents($this->logFile, PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Читает последние строки из файла
     *
     * @param string $file
     * @param int $lines Количество строк
     * @return array Массив строк
     */
    private function readLastLines(string $file, int $lines): array
    {
        $result = [];
        
        if (!file_exists($file) || !is_readable($file)) {
            return $result;
        }
        
        $f = fopen($file, 'r');
        if ($f === false) {
            return $result;
        }
        
        $buffer = [];
        $pos = -1;
        $currentLines = 0;
        $filesize = filesize($file);
        
        while ($currentLines < $lines && abs($pos) < $filesize) {
            $pos--;
            fseek($f, $pos, SEEK_END);
            $char = fgetc($f);
            
            if ($char === "\n") {
                $line = implode('', array_reverse($buffer));
                if (!empty(trim($line))) {
                    $result[] = $line;
                    $currentLines++;
                }
                $buffer = [];
            } else {
                $buffer[] = $char;
            }
        }
        
        // Добавляем последнюю строку, если буфер не пуст
        if (!empty($buffer)) {
            $line = implode('', array_reverse($buffer));
            if (!empty(trim($line))) {
                $result[] = $line;
            }
        }
        
        fclose($f);
        return array_reverse($result);
    }
}
