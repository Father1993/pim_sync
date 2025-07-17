<?php
/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync
 * @author Уровень
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
     * @param string $api_url URL API CS-Cart
     * @param string $email Email пользователя
     * @param string $api_key API ключ пользователя
     * @param LoggerInterface|null $logger Логгер
     */
    public function __construct($api_url, $email, $api_key, LoggerInterface $logger = null)
    {
        parent::__construct($api_url, $logger);
        
        $this->email = $email;
        $this->api_key = $api_key;
    }
    
    /**
     * Проверить соединение с API
     *
     * @return bool Результат проверки соединения
     */
    public function testConnection()
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
     * @param array $params Параметры запроса
     * @return array Список категорий
     * @throws Exception При ошибке запроса
     */
    public function getCategories(array $params = []): array
    {
        $endpoint = '/api/categories/';
        
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        return $this->makeRequest($endpoint);
    }
    
    /**
     * Создает или обновляет категорию в CS-Cart
     *
     * @param array $category Данные категории
     * @param int|null $categoryId ID категории для обновления (null для создания новой)
     * @return array Результат операции
     * @throws Exception При ошибке запроса
     */
    public function updateCategory($category, $categoryId = null)
    {
        if ($categoryId) {
            // Обновление существующей категории
            return $this->makeRequest('/api/categories/' . $categoryId, 'PUT', $category);
        } else {
            // Создание новой категории
            return $this->makeRequest('/api/categories/', 'POST', $category);
        }
    }
    
    /**
     * Получает список продуктов из CS-Cart
     *
     * @param array $params Параметры запроса
     * @return array Список продуктов
     * @throws Exception При ошибке запроса
     */
    public function getProducts($params = [])
    {
        $endpoint = '/api/products/';
        
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        return $this->makeRequest($endpoint);
    }
    
    /**
     * Создает или обновляет продукт в CS-Cart
     *
     * @param array $product Данные продукта
     * @param int|null $productId ID продукта для обновления (null для создания нового)
     * @return array Результат операции
     * @throws Exception При ошибке запроса
     */
    public function updateProduct($product, $productId = null)
    {
        if ($productId) {
            // Обновление существующего продукта
            return $this->makeRequest('/api/products/' . $productId, 'PUT', $product);
        } else {
            // Создание нового продукта
            return $this->makeRequest('/api/products/', 'POST', $product);
        }
    }
    
    /**
     * Получить HTTP заголовки для запроса с базовой авторизацией
     *
     * @return array Массив заголовков
     */
    protected function getHeaders()
    {
        // Получаем базовые заголовки
        $headers = parent::getHeaders();
        
        // Формируем Basic HTTP авторизацию
        $auth_string = base64_encode($this->email . ':' . $this->api_key);
        $headers[] = 'Authorization: Basic ' . $auth_string;
        
        return $headers;
    }
}
