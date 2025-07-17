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
     * @param string $api_url
     * @param string $login
     * @param string $password
     * @param LoggerInterface|null $logger
     */
    public function __construct(string $api_url, string $login, string $password, ?LoggerInterface $logger = null)
    {
        parent::__construct($api_url, $logger);
        $this->login = $login;
        $this->password = $password;
    }
    
    /**
     * Проверяет соединение с API
     *
     * @return bool
     */
    public function testConnection(): bool
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
     * @param string $endpoint
     * @param string $method
     * @param array|null $data
     * @param bool $use_auth Использовать ли авторизацию
     * @return array Ответ от API
     * @throws Exception
     */
    public function makeRequest(string $endpoint, string $method = 'GET', ?array $data = null, bool $use_auth = true): array
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
     * @throws Exception
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
     * @param string|null $scope ID каталога
     * @param array $params
     * @return array Список категорий
     * @throws Exception
     */
    public function getCategories(?string $scope = null, array $params = []): array
    {
        if ($scope === null) {
            throw new ApiAuthException('Catalog ID is required for PIM API', 400);
        }
        $endpoint = '/api/v1/catalogs/' . $scope . '/categories';
        // Добавляем параметры в URL
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->makeRequest($endpoint, 'GET');
    }

    
    /**
     * Получает список продуктов из PIM
     *
     * @param string|null $scope ID каталога
     * @param array $params
     * @return array Список продуктов
     * @throws Exception
     */
    public function getProducts(?string $scope = null, array $params = []): array
    {
        if ($scope === null) {
            throw new ApiAuthException('Catalog ID is required for PIM API', 400);
        }
        $endpoint = '/api/v1/catalogs/' . $scope . '/products';
        // Добавляем параметры в URL
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        return $this->makeRequest($endpoint, 'GET');
    }
    
    /**
     * Получает измененные продукты за указанный период
     *
     * @param string $catalogId
     * @param int $days Количество дней для поиска изменений
     * @param array $params 
     * @return array Список измененных продуктов
     * @throws Exception
     */
    public function getChangedProducts(string $catalogId, int $days = 1, array $params = []): array
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
     * @return array
     */
    protected function getHeaders(): array
    {
        $headers = parent::getHeaders();
        
        // Добавляем специфичные заголовки для PIM API
        if (defined('PIM_SYNC_API_VERSION')) {
            $headers[] = 'X-PIM-Version: ' . PIM_SYNC_API_VERSION;
        }
        
        return $headers;
    }
}
