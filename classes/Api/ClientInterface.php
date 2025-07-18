<?php
/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync\
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
     * @return bool
     */
    public function testConnection(): bool;
    
    /**
     * Выполняет запрос к API
     *
     * @param string $endpoint
     * @param string $method
     * @param array|null $data
     * @param bool $use_auth
     * @return array Ответ от API
     * @throws \Tygh\Addons\PimSync\Exception\ApiAuthException При ошибке авторизации
     * @throws \Exception При других ошибках запроса
     */
    public function makeRequest(string $endpoint, string $method = 'GET', ?array $data = null, bool $use_auth = true): array;
    
    /**
     * Получает список категорий
     * 
     * @param string|null $scope Область данных (каталог для PIM, игнорируется в CS-Cart)
     * @param array $params Дополнительные параметры запроса
     * @return array Список категорий
     */
    public function getCategories(?string $scope = null, array $params = []): array;
    
    /**
     * Получает список продуктов
     * 
     * @param string $catalogId ID каталога (обязательный для PIM, игнорируется в CS-Cart)
     * @param array $params Дополнительные параметры запроса
     * @return array Список продуктов
     */
    public function getProducts(string $catalogId, array $params = []): array;
}
