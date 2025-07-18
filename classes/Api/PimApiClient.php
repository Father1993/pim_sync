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
    private static $catalogs_cache = null; // Кэш для каталогов
    
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
        // Проверяем токен и аутентифицируемся при необходимости
        if ($use_auth && ($this->token === null || time() >= $this->token_expires)) {
            $this->authenticate();
        }
        
        return parent::makeRequest($endpoint, $method, $data, $use_auth);
    }
    
    /**
     * Аутентификация в PIM API
     *
     * @throws ApiAuthException
     */
    private function authenticate()
    {
        try {
            $response = parent::makeRequest('/sign-in/', 'POST', [
                'login' => $this->login,
                'password' => $this->password,
                'remember' => true
            ], false);
            
            if (!isset($response['success']) || !$response['success'] || !isset($response['data']['access']['token'])) {
                throw new ApiAuthException('Ошибка авторизации: неверный ответ от API');
            }
            
            $this->token = $response['data']['access']['token'];
            $this->token_expires = time() + 3600; // 1 час
            
            $this->logger?->log('Успешная авторизация в PIM API', 'info');
            $this->logger?->log('Токен получен, срок действия: ' . date('Y-m-d H:i:s', $this->token_expires), 'debug');
            
        } catch (Exception $e) {
            $this->logger?->log('Ошибка авторизации в PIM API: ' . $e->getMessage(), 'error');
            throw new ApiAuthException('Ошибка авторизации: ' . $e->getMessage());
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
        // Проверяем кэш
        if (self::$catalogs_cache !== null) {
            $this->logger?->log('Использование кэшированных каталогов', 'debug');
            return self::$catalogs_cache;
        }
        
        $endpoint = '/catalog';
        if (!empty($params)) {
            $endpoint .= '?' . http_build_query($params);
        }
        $response = $this->makeRequest($endpoint, 'GET');
        if (isset($response['data']) && $response['success'] === true) {
            self::$catalogs_cache = $response['data']; // Кэшируем результат
            return $response['data'];
        }
        
        self::$catalogs_cache = $response; // Кэшируем результат
        return $response;
    }

    /**
     * Получает конкретный каталог по ID
     *
     * @param string $catalogId ID каталога
     * @return array Данные каталога
     * @throws Exception
     */
    public function getCatalogById(string $catalogId): array
    {
        $endpoint = '/catalog/' . $catalogId;
        
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
        
        // Получаем конкретный каталог по ID
        $catalog = $this->getCatalogById($scope);
        
        // Возвращаем найденный каталог как массив (для совместимости с существующим кодом)
        return [$catalog];
    }

    
    /**
     * Получает товары из указанного каталога
     *
     * @param string $catalogId ID каталога
     * @param array $params Параметры запроса
     * @return array Список товаров
     * @throws Exception
     */
    public function getProducts(string $catalogId, array $params = []): array
    {
        // Используем scroll API для получения товаров
        $endpoint = '/product/scroll';
        $queryParams = [
            'catalogId' => $catalogId,
            'size' => $params['limit'] ?? 50,
            'page' => $params['page'] ?? 0
        ];
        
        $url = $endpoint . '?' . http_build_query($queryParams);
        
        $response = $this->makeRequest($url, 'GET');
        
        if (isset($response['data']['productElasticDtos']) && $response['success'] === true) {
            return $response['data']['productElasticDtos'];
        }
        
        return [];
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
     * @param string $catalogId ID каталога
     * @param int $days Количество дней для поиска изменений
     * @param array $params Дополнительные параметры
     * @return array Список измененных продуктов
     * @throws Exception
     */
    public function getChangedProducts(string $catalogId, int $days = 1, array $params = []): array
    {
        $endpoint = '/product/scroll';
        $params['catalogId'] = $catalogId;
        $params['days'] = $days;
        
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
     * Получает заголовки для HTTP запросов
     *
     * @return array
     */
    protected function getHeaders(): array
    {
        $headers = parent::getHeaders();
        
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
            $this->logger?->log('Добавлен токен авторизации в запрос', 'debug');
        }
        
        return $headers;
    }
}
