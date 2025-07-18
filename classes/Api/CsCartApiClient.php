<?php
/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */
namespace Tygh\Addons\PimSync\Api;
use Exception;
use Tygh\Addons\PimSync\Utils\LoggerInterface;
/**
 * Клиент для работы с CS-Cart REST API
 */
class CsCartApiClient extends BaseApiClient
{
    private  string $email;
    private string $api_key;
    
    /**
     * Конструктор
     * 
     * @param string $api_url URL
     * @param string $email Email
     * @param string $api_key API
     * @param LoggerInterface|null $logger
     */
    public function __construct(string $api_url, string $email, string $api_key, ?LoggerInterface $logger = null)
    {
        parent::__construct($api_url, $logger);
        
        $this->email = $email;
        $this->api_key = $api_key;
    }
    
    /**
     * Проверить соединение с API
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $response = $this->makeRequest('/api/2.0/version', 'GET');
            return isset($response['Version']) && !empty($response['Version']);
        } catch (Exception $e) {
            $this->logger?->log('Ошибка тестирования соединения с CS-Cart API: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Получает список категорий из CS-Cart
     *
     * @param string|null $scope Не используется в CS-Cart API
     * @param array $params
     * @return array Список категорий
     * @throws Exception
     */
    public function getCategories(?string $scope = null, array $params = []): array
    {
        $endpoint = '/api/2.0/categories';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->makeRequest($endpoint);
    }

    /**
     * Получает список продуктов из CS-Cart
     *
     * @param string|null $scope Не используется в CS-Cart API
     * @param array $params
     * @return array Список продуктов
     * @throws Exception
     */
    public function getProducts(?string $scope = null, array $params = []): array
    {
        $endpoint = '/api/2.0/products';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->makeRequest($endpoint);
    }

    /**
     * Простой GET запрос для совместимости
     *
     * @param string $endpoint
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function get(string $endpoint, array $params = []): array
    {
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->makeRequest($endpoint, 'GET');
    }

    /**
     * Создает или обновляет категорию в CS-Cart
     *
     * @param array $category 
     * @param int|null $categoryId ID категории для обновления (null для создания новой)
     * @return array
     * @throws Exception
     */
    public function updateCategory(array $category, ?int $categoryId = null): array
    {
        if ($categoryId) {
            // Обновление существующей категории
            $this->logger?->log("Обновление категории в CS-Cart ID: $categoryId", 'debug');
            return $this->makeRequest('/api/2.0/categories/' . $categoryId, 'PUT', $category);
        } else {
            // Создание новой категории
            $this->logger?->log("Создание новой категории в CS-Cart: {$category['category']}", 'debug');
            return $this->makeRequest('/api/2.0/categories', 'POST', $category);
        }
    }

    /**
     * Создает или обновляет продукт в CS-Cart
     *
     * @param array $product Данные продукта
     * @param int|null $productId ID продукта для обновления (null для создания нового)
     * @return array
     * @throws Exception
     */
    public function updateProduct(array $product, ?int $productId = null): array
    {
        if ($productId) {
            // Обновление существующего продукта
            return $this->makeRequest('/api/2.0/products/' . $productId, 'PUT', $product);
        } else {
            // Создание нового продукта
            return $this->makeRequest('/api/2.0/products', 'POST', $product);
        }
    }
    
    /**
     * Получить HTTP заголовки для запроса с базовой авторизацией
     *
     * @return array
     */
    protected function getHeaders(): array
    {
        // Получаем базовые заголовки
        $headers = parent::getHeaders();
        
        // Формируем Basic HTTP авторизацию
        $auth_string = base64_encode($this->email . ':' . $this->api_key);
        $headers[] = 'Authorization: Basic ' . $auth_string;
        
        return $headers;
    }
}
