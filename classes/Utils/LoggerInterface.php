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
     * Логирование сообщения
     *
     * @param string $message Текст сообщения
     * @param string $level Уровень логирования (info, debug, warning, error, critical)
     * @return void
     */
    public function log(string $message, string $level = 'info'): void;
    
    /**
     * Создает запись о начале синхронизации в БД
     *
     * @param string $sync_type Тип синхронизации
     * @param int $company_id ID компании
     * @return int ID созданной записи
     */
    public function createLogEntry(string $sync_type = 'manual', int $company_id = 0): int;
    
    /**
     * Обновляет запись в БД о синхронизации
     *
     * @param int $log_id ID записи
     * @param array $data Данные для обновления
     * @return void
     */
    public function updateLogEntry(int $log_id, array $data): void;
    
    /**
     * Получает записи логов из БД
     *
     * @param int $limit Количество записей
     * @param int $company_id ID компании
     * @return array
     */
    public function getLogEntries(int $limit = 10, int $company_id = 0): array;
}
