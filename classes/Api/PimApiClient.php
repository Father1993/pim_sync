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
use Tygh\Addons\PimSync\Exception\ApiAuthException;
use Tygh\Addons\PimSync\Utils\LoggerInterface;

/**
 * Клиент для работы с Compo PIM API
 */
class PimApiClient extends BaseApiClient
{
    private ?string $token = null;
    private int $token_expires = 0;
    private string $login;
    private string $password;
    
    /**
     * PimApiClient constructor
     *
     * @param string $api_url URL API
     * @param string $login Логин
     * @param string $password Пароль
     * @param LoggerInterface|null $logger Логгер
     */
    public function __construct($api_url, $login, $password, LoggerInterface $logger = null)
    {
        parent::__construct($api_url, $logger);
        
        $this->login = $login;
        $this->password = $password;
    }
    
    /**
     * Проверяет соединение с API
     *
     * @return bool Результат проверки соединения
     */
    public function testConnection()
    {
        try {
            $this->authenticate();
            return $this->token !== null;
        } catch (Exception $e) {
            $this->logger?->log('Ошибка проверки соединения с PIM API: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Выполняет запрос к API с автоматической авторизацией
     *
     * @param string $endpoint Конечная точка API
     * @param string $method HTTP метод
     * @param mixed $data Данные для отправки
     * @param bool $use_auth Использовать ли авторизацию
     * @return array Ответ от API
     * @throws Exception При ошибке запроса
     */
    public function makeRequest($endpoint, $method = 'GET', $data = null, $use_auth = true)
    {
        // Проверяем необходимость авторизации
        if ($use_auth && (!$this->token || time() >= $this->token_expires)) {
            $this->logger?->log('Токен отсутствует или истек, запрашиваем новый', 'debug');
            $this->authenticate();
        }
        
        // Если требуется аутентификация, добавляем токен в заголовки
        $headers = $this->getHeaders();
        if ($use_auth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
            $this->logger?->log('Добавлен токен авторизации в запрос', 'debug');
        }
        $this->headers = $headers;
        return parent::makeRequest($endpoint, $method, $data);
    }
    
    /**
     * Авторизация и получение токена
     *
     * @throws Exception При ошибке авторизации
     * @return void
     */
    private function authenticate()
    {
        try {
            $auth_data = [
                'login' => $this->login,
                'password' => $this->password,
                'remember' => true
            ];
            
            // Выполняем запрос без авторизации
            $response = parent::makeRequest('/sign-in/', 'POST', $auth_data);
            
            if ($response && isset($response['success']) && $response['success'] === true) {

                if (!isset($response['data']['access']['token'])) {
                    throw new ApiAuthException('PIM API authentication failed: token not found in response');
                }
                
                $this->token = $response['data']['access']['token'];
                $this->token_expires = time() + 3300;
                $this->logger?->log('Успешная авторизация в PIM API', 'info');
                $this->logger?->log('Токен получен, срок действия: ' . date('Y-m-d H:i:s', $this->token_expires), 'debug');
            } else {
                $error_message = 'PIM API authentication failed';
                if (isset($response['message'])) {
                    $error_message .= ': ' . $response['message'];
                }
                throw new ApiAuthException($error_message, 0, $response);
            }
        } catch (Exception $e) {
            $this->logger?->log('Ошибка авторизации в PIM API: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    /**
     * Получает список категорий из PIM
     *
     * @param string $catalogId ID каталога
     * @param array $params Дополнительные параметры запроса
     * @return array Список категорий
     * @throws Exception При ошибке запроса
     */
    public function getCategories($catalogId, $params = [])
    {
        $endpoint = '/api/v1/catalogs/' . $catalogId . '/categories';
        
        // Добавляем параметры в URL
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        return $this->makeRequest($endpoint, 'GET');
    }
    
    /**
     * Получает список продуктов из PIM
     *
     * @param string $catalogId ID каталога
     * @param array $params Дополнительные параметры запроса
     * @return array Список продуктов
     * @throws Exception При ошибке запроса
     */
    public function getProducts($catalogId, $params = [])
    {
        $endpoint = '/api/v1/catalogs/' . $catalogId . '/products';
        
        // Добавляем параметры в URL
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        
        return $this->makeRequest($endpoint, 'GET');
    }
    
    /**
     * Получает измененные продукты за указанный период
     *
     * @param string $catalogId ID каталога
     * @param int $days Количество дней для поиска изменений
     * @param array $params Дополнительные параметры запроса
     * @return array Список измененных продуктов
     * @throws Exception При ошибке запроса
     */
    public function getChangedProducts($catalogId, $days = 1, $params = [])
    {
        // Рассчитываем дату для delta sync
        $date = date('Y-m-d', strtotime('-' . (int)$days . ' days'));
        
        $defaultParams = [
            'changed_since' => $date,
            'limit' => 100,
            'page' => 1
        ];
        
        $mergedParams = array_merge($defaultParams, $params);
        
        return $this->getProducts($catalogId, $mergedParams);
    }
    
    /**
     * Получает заголовки для запросов
     *
     * @return array Массив заголовков
     */
    protected function getHeaders()
    {
        $headers = parent::getHeaders();
        
        // Добавляем специфичные заголовки для PIM API
        if (defined('PIM_SYNC_API_VERSION')) {
            $headers[] = 'X-PIM-Version: ' . PIM_SYNC_API_VERSION;
        }
        
        return $headers;
    }
}
