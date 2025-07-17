<?php
declare(strict_types=1);

/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */

namespace Tygh\Addons\PimSync\Api;

/**
 * Интерфейс для API клиентов
 */
interface ClientInterface
{
    /**
     * Проверяет соединение с API
     *
     * @return bool Результат проверки соединения
     */
    public function testConnection(): bool;
    
    /**
     * Выполняет запрос к API
     *
     * @param string $endpoint Конечная точка API
     * @param string $method HTTP метод
     * @param array|null $data Данные для отправки
     * @param bool $use_auth Использовать ли авторизацию
     * @return array Ответ от API
     * @throws \Tygh\Addons\PimSync\Exception\ApiAuthException При ошибке авторизации
     * @throws \Exception При других ошибках запроса
     */
    public function makeRequest(string $endpoint, string $method = 'GET', ?array $data = null, bool $use_auth = true): array;
}
