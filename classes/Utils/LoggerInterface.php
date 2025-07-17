<?php
/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync\Utils
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */
namespace Tygh\Addons\PimSync\Utils;

interface LoggerInterface
{
    /**
     * Записывает сообщение в лог
     *
     * @param string $message
     * @param string $level Уровень логирования (debug, info, warning, error)
     * @return void
     */
    public function log(string $message, string $level = 'info'): void;
    
    /**
     * Получает последние записи логов
     *
     * @param int $limit
     * @param string|null $level
     * @return array Массив записей логов
     */
    public function getRecentLogs(int $limit = 50, ?string $level = null): array;
    
    /**
     * Очищает лог-файл
     *
     * @return bool
     */
    public function clearLogs(): bool;
}
