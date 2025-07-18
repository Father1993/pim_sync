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
     * Получает список всех каталогов из PIM
     *
     * @param array $params Дополнительные параметры
     * @return array Список каталогов
     * @throws Exception
     */
    public function getCatalogs(array $params = []): array
    {
        $endpoint = '/catalog';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        $response = $this->makeRequest($endpoint, 'GET');
        if (isset($response['data']) && $response['success'] === true) {
            return $response['data'];
        }
        return $response;
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
        
        // Сначала получаем все каталоги
        $catalogs = $this->getCatalogs($params);
        
        // Находим нужный каталог по ID
        $targetCatalog = null;
        foreach ($catalogs as $catalog) {
            if ($catalog['id'] == $scope) {
                $targetCatalog = $catalog;
                break;
            }
        }
        
        if ($targetCatalog === null) {
            throw new Exception("Каталог с ID {$scope} не найден");
        }
        
        // Возвращаем найденный каталог как массив (для совместимости с существующим кодом)
        return [$targetCatalog];
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
        $endpoint = '/product/scroll';
        $params['catalogId'] = $scope;
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        $response = $this->makeRequest($endpoint, 'GET');
        if (isset($response['data']) && isset($response['data']['productElasticDtos']) && $response['success'] === true) {
            return $response['data']['productElasticDtos'];
        }
        return isset($response['data']) ? $response['data'] : $response;
    }

    /**
     * Получает товар по ID
     *
     * @param string $productId ID товара
     * @return array Данные товара
     * @throws Exception
     */
    public function getProductById(string $productId): array
    {
        $endpoint = '/product/' . $productId;
        
        $response = $this->makeRequest($endpoint, 'GET');
        
        // Проверяем структуру ответа и возвращаем данные
        if (isset($response['data']) && $response['success'] === true) {
            return $response['data'];
        }
        
        return $response;
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
        // Базовые параметры для запроса
        $scrollParams = [
            'catalogId' => $catalogId,
            'day' => $days
        ];
        $mergedParams = array_merge($scrollParams, $params);
        $endpoint = '/product/scroll';        
        if (!empty($mergedParams)) {
            $endpoint .= '?' . http_build_query($mergedParams);
        }        
        $response = $this->makeRequest($endpoint, 'GET');
        if (isset($response['data']) && isset($response['data']['productElasticDtos']) && $response['success'] === true) {
            return $response['data']['productElasticDtos'];
        }
        return isset($response['data']) ? $response['data'] : $response;
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
