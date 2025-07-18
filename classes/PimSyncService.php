<?php
/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */

namespace Tygh\Addons\PimSync;

use Tygh\Addons\PimSync\Api\PimApiClient;
use Tygh\Addons\PimSync\Api\CsCartApiClient;
use Tygh\Addons\PimSync\Exception\ApiAuthException;
use Tygh\Addons\PimSync\Utils\LoggerInterface;
use Exception;

/**
 * Сервис синхронизации между PIM и CS-Cart
 */
class PimSyncService
{
    /** @var PimApiClient */
    private $pimClient;
    
    /** @var CsCartApiClient */
    private $csCartClient;
    
    /** @var int */
    private $companyId;
    
    /** @var int */
    private $storefrontId;
    
    /** @var LoggerInterface|null */
    private $logger;
    
    /** @var array */
    private $storefrontConfig = [];
    
    /** @var string */
    private const PIM_SYNC_UID_FIELD = 'pim_sync_uid';
    
    /** @var string */
    private const PIM_ID_FIELD = 'pim_id';
    
    /**
     * Конструктор сервиса синхронизации
     *
     * @param PimApiClient $pimClient Клиент PIM API
     * @param CsCartApiClient $csCartClient Клиент CS-Cart API
     * @param int $companyId ID компании в CS-Cart
     * @param int $storefrontId ID витрины в CS-Cart
     * @param LoggerInterface|null $logger Логгер
     */
    public function __construct(
        PimApiClient $pimClient,
        CsCartApiClient $csCartClient,
        int $companyId = 0,
        int $storefrontId = 1,
        ?LoggerInterface $logger = null
    ) {
        $this->pimClient = $pimClient;
        $this->csCartClient = $csCartClient;
        $this->companyId = $companyId;
        $this->storefrontId = $storefrontId;
        $this->logger = $logger;
        
        // Загружаем конфигурацию витрин
        $this->loadStorefrontConfig();
    }
    
    /**
     * Загружает конфигурацию витрин из CS-Cart
     */
    private function loadStorefrontConfig(): void
    {
        try {
            $this->logger?->log('Загрузка конфигурации витрин из CS-Cart', 'info');
            
            // Получаем список всех компаний напрямую из БД
            $companies = db_get_array(
                "SELECT company_id, company as name, status, email 
                 FROM ?:companies 
                 WHERE status = 'A' 
                 ORDER BY company_id"
            );
            
            if (!empty($companies)) {
                foreach ($companies as $company) {
                    $this->storefrontConfig[$company['company_id']] = [
                        'company_id' => $company['company_id'],
                        'name' => $company['name'],
                        'seo_name' => strtolower(str_replace(' ', '_', $company['name'])),
                        'status' => $company['status'],
                        'email' => $company['email'] ?? ''
                    ];
                }
                
                $this->logger?->log('Загружено компаний: ' . count($this->storefrontConfig), 'info');
            }
            
        } catch (Exception $e) {
            $this->logger?->log('Ошибка при загрузке конфигурации витрин: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Получает конфигурацию для определенной витрины
     *
     * @param int $companyId ID компании
     * @return array|null Конфигурация витрины или null если не найдена
     */
    public function getStorefrontConfig(int $companyId): ?array
    {
        return $this->storefrontConfig[$companyId] ?? null;
    }
    
    /**
     * Получает список всех доступных витрин
     *
     * @return array Список витрин
     */
    public function getAvailableStorefronts(): array
    {
        return $this->storefrontConfig;
    }
    
    /**
     * Определяет целевую витрину для каталога PIM
     *
     * @param string $catalogId ID каталога PIM
     * @return array Конфигурация витрины [company_id, storefront_id]
     */
    public function getTargetStorefrontForCatalog(string $catalogId): array
    {
        // Загружаем конфигурацию маппинга из файла
        $mappingConfigFile = __DIR__ . '/../config/catalog_mapping.php';
        $mappingConfig = [];
        
        if (file_exists($mappingConfigFile)) {
            $mappingConfig = include $mappingConfigFile;
        }
        
        // Ищем конфигурацию для конкретного каталога
        if (isset($mappingConfig[$catalogId])) {
            $config = $mappingConfig[$catalogId];
            return [
                'company_id' => $config['company_id'],
                'storefront_id' => $config['storefront_id'],
                'name' => $config['name'] ?? $catalogId,
                'sync_products' => $config['sync_products'] ?? true,
                'sync_categories' => $config['sync_categories'] ?? true
            ];
        }
        
        // Используем дефолтную конфигурацию
        $defaultConfig = $mappingConfig['default'] ?? [
            'company_id' => $this->companyId,
            'storefront_id' => $this->storefrontId
        ];
        
        return [
            'company_id' => $defaultConfig['company_id'],
            'storefront_id' => $defaultConfig['storefront_id'],
            'name' => $defaultConfig['name'] ?? 'Каталог по умолчанию',
            'sync_products' => $defaultConfig['sync_products'] ?? false,
            'sync_categories' => $defaultConfig['sync_categories'] ?? false
        ];
    }
    
    /**
     * Проверяет существование и активность витрины
     *
     * @param int $companyId ID компании
     * @return bool true если витрина существует и активна
     */
    private function validateStorefront(int $companyId): bool
    {
        // Специальная обработка для company_id = 0 (общие категории)
        if ($companyId === 0) {
            return true;
        }
        
        $config = $this->getStorefrontConfig($companyId);
        $isValid = $config !== null && $config['status'] === 'A';
        
        if (!$isValid) {
            $this->logger?->log("Витрина с ID {$companyId} не найдена или неактивна", 'warning');
        }
        
        return $isValid;
    }
    
    /**
     * Проверяет корректность параметров витрины
     *
     * @param int $companyId ID компании
     * @param int $storefrontId ID витрины
     * @return array Результат валидации [valid => bool, message => string]
     */
    private function validateStorefrontParameters(int $companyId, int $storefrontId): array
    {
        $result = ['valid' => true, 'message' => ''];
        
        // Проверка storefront_id
        if ($storefrontId < 1) {
            $result['valid'] = false;
            $result['message'] = "Некорректный storefront_id: {$storefrontId}";
            return $result;
        }
        
        // Проверка company_id
        if ($companyId < 0) {
            $result['valid'] = false;
            $result['message'] = "Некорректный company_id: {$companyId}";
            return $result;
        }
        
        // Проверка существования витрины
        if (!$this->validateStorefront($companyId)) {
            $result['valid'] = false;
            $result['message'] = "Витрина с ID {$companyId} не найдена или неактивна";
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Получает информацию о витрине для логирования
     *
     * @param int $companyId ID компании
     * @return string Описание витрины
     */
    private function getStorefrontDescription(int $companyId): string
    {
        if ($companyId === 0) {
            return 'Общие категории для всех продавцов';
        }
        
        $config = $this->getStorefrontConfig($companyId);
        if ($config) {
            return "Витрина \"{$config['name']}\" (ID: {$companyId})";
        }
        
        return "Витрина с ID {$companyId}";
    }
    
    /**
     * Синхронизирует категории из PIM в CS-Cart
     *
     * @param string $catalogId ID каталога в PIM
     * @param int|string|null $companyId ID компании/продавца в CS-Cart (если null, определится автоматически)
     * @param int|null $storefrontId ID витрины в CS-Cart (если null, определится автоматически)
     * @return array Результат синхронизации категорий
     */
    public function syncCategories(string $catalogId, $companyId = null, ?int $storefrontId = null): array
    {
        $result = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        try {
            $this->logger?->log("Начало синхронизации категорий для каталога ID: $catalogId", 'info');
            
            // Определяем целевую витрину для каталога
            $storefrontConfig = $this->getTargetStorefrontForCatalog($catalogId);
            $targetCompanyId = $companyId ?? $storefrontConfig['company_id'];
            $targetStorefrontId = $storefrontId ?? $storefrontConfig['storefront_id'];
            
            $storefrontDescription = $this->getStorefrontDescription($targetCompanyId);
            $this->logger?->log("Целевая витрина: {$storefrontDescription} (storefront_id={$targetStorefrontId})", 'info');
            
            // Проверяем корректность параметров витрины
            $validation = $this->validateStorefrontParameters($targetCompanyId, $targetStorefrontId);
            if (!$validation['valid']) {
                throw new Exception($validation['message']);
            }
            
            // Проверяем права на синхронизацию
            if (!$storefrontConfig['sync_categories']) {
                throw new Exception("Синхронизация категорий отключена для каталога {$catalogId}");
            }
            
            // Получаем категории из PIM
            $categories = $this->pimClient->getCategories($catalogId);
            
            if (empty($categories)) {
                $this->logger?->log("Категории не найдены в каталоге ID: $catalogId", 'warning');
                return $result;
            }
            
            // Первый элемент - корневая категория каталога
            $rootCategory = $categories[0];
            $result['total'] = $this->countCategoriesRecursive($rootCategory);
            
            // УДАЛЯЕМ: не используем syncUidMap, полагаемся на таблицы маппинга
            // $existingCategories = $this->getCsCartCategories($targetCompanyId, $targetStorefrontId);
            // $syncUidMap = $this->buildSyncUidMap($existingCategories);
            
            // Загружаем карту маппинга PIM ID -> CS-Cart ID из БД
            $pimIdMap = $this->buildPimIdToCsCartIdMap($catalogId, $targetCompanyId, $targetStorefrontId);
            
            // Синхронизируем категории рекурсивно
            $syncResult = $this->processCategoryTree($rootCategory, 0, $targetCompanyId, $targetStorefrontId, $catalogId, $pimIdMap);
            
            // Обновляем результат
            $result['created'] = $syncResult['created'];
            $result['updated'] = $syncResult['updated'];
            $result['failed'] = $syncResult['failed'];
            $result['details'] = $syncResult['details'];
            
            $this->logger?->log("Завершена синхронизация категорий: создано {$result['created']}, обновлено {$result['updated']}, с ошибками {$result['failed']}", 'info');
        } catch (Exception $e) {
            $this->logger?->log("Ошибка при синхронизации категорий: " . $e->getMessage(), 'error');
            $result['failed'] = $result['total'];
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Синхронизирует товары из PIM в CS-Cart
     *
     * @param array $products Массив товаров из PIM
     * @param string $catalogId ID каталога в PIM
     * @param int|string $companyId ID компании в CS-Cart
     * @param int $storefrontId ID витрины в CS-Cart
     * @return array Результат синхронизации
     */
    private function syncProducts(array $products, string $catalogId, $companyId, int $storefrontId = 1): array
    {
        $result = [
            'total' => count($products),
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'details' => []
        ];
        
        try {
            $storefrontDescription = $this->getStorefrontDescription($companyId);
            $this->logger?->log("Начало синхронизации товаров для {$storefrontDescription}. Всего: " . count($products), 'info');
            
            // Проверяем корректность параметров витрины
            $validation = $this->validateStorefrontParameters($companyId, $storefrontId);
            if (!$validation['valid']) {
                throw new Exception($validation['message']);
            }
            
            // Загружаем карту маппинга PIM ID категорий -> CS-Cart ID из БД
            $categoryMap = $this->buildPimIdToCsCartIdMap($catalogId, $companyId, $storefrontId);
            
            // Загружаем существующий маппинг товаров
            $productMap = $this->buildProductPimIdMap($catalogId, $companyId, $storefrontId);
            
            foreach ($products as $product) {
                try {
                    // Определяем категории товара
                    $categoryIds = $this->getProductCategories($product, $categoryMap);
                    
                    if (empty($categoryIds)) {
                        $this->logger?->log("Товар {$product['header']} (ID: {$product['id']}) пропущен - нет категорий в CS-Cart", 'warning');
                        $result['failed']++;
                        continue;
                    }
                    
                    // Проверяем существование товара
                    $existingProductId = $productMap[$product['id']] ?? null;
                    
                    // Подготавливаем данные товара
                    $productData = $this->prepareProductData($product, $categoryIds, $companyId, $storefrontId);
                    
                    if ($existingProductId) {
                        // Обновляем существующий товар
                        $success = $this->updateCsCartProduct($existingProductId, $productData);
                        
                        if ($success) {
                            $result['updated']++;
                            $this->logger?->log("Обновлен товар: {$product['header']} (CS-Cart ID: $existingProductId)", 'info');
                        } else {
                            $result['failed']++;
                            $this->logger?->log("Ошибка обновления товара: {$product['header']}", 'error');
                        }
                    } else {
                        // Создаем новый товар
                        $newProductId = $this->createCsCartProduct($productData);
                        
                        if ($newProductId) {
                            $result['created']++;
                            $this->logger?->log("Создан товар: {$product['header']} (CS-Cart ID: $newProductId)", 'info');
                            
                            // Сохраняем маппинг
                            $this->saveProductMapping($product, $newProductId, $catalogId, $companyId);
                            $productMap[$product['id']] = $newProductId;
                        } else {
                            $result['failed']++;
                            $this->logger?->log("Ошибка создания товара: {$product['header']}", 'error');
                        }
                    }
                    
                } catch (Exception $e) {
                    $result['failed']++;
                    $this->logger?->log("Ошибка при обработке товара {$product['header']}: " . $e->getMessage(), 'error');
                }
            }
            
            $this->logger?->log("Завершена синхронизация товаров: создано {$result['created']}, обновлено {$result['updated']}, с ошибками {$result['failed']}", 'info');
            
        } catch (Exception $e) {
            $this->logger?->log("Критическая ошибка при синхронизации товаров: " . $e->getMessage(), 'error');
            $result['error'] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Определяет категории товара на основе catalogAdditional
     *
     * @param array $product Товар из PIM
     * @param array $categoryMap Карта маппинга PIM категорий к CS-Cart
     * @return array Массив ID категорий в CS-Cart
     */
    private function getProductCategories(array $product, array $categoryMap): array
    {
        $categoryIds = [];
        
        // Проверяем catalogAdditional
        if (!empty($product['catalogAdditional']) && is_array($product['catalogAdditional'])) {
            foreach ($product['catalogAdditional'] as $pimCategoryId) {
                if (isset($categoryMap[$pimCategoryId])) {
                    $categoryIds[] = $categoryMap[$pimCategoryId];
                } else {
                    $this->logger?->log("Категория PIM ID $pimCategoryId не найдена в маппинге для товара {$product['header']}", 'warning');
                }
            }
        }
        
        // Если категории не найдены через catalogAdditional, пробуем использовать catalogId
        if (empty($categoryIds) && !empty($product['catalogId'])) {
            if (isset($categoryMap[$product['catalogId']])) {
                $categoryIds[] = $categoryMap[$product['catalogId']];
            }
        }
        
        return array_unique($categoryIds);
    }
    
    /**
     * Подготавливает данные товара для CS-Cart
     *
     * @param array $product Товар из PIM
     * @param array $categoryIds ID категорий в CS-Cart
     * @param int $companyId ID компании в CS-Cart
     * @param int $storefrontId ID витрины в CS-Cart
     * @return array Подготовленные данные
     */
    private function prepareProductData(array $product, array $categoryIds, int $companyId, int $storefrontId): array
    {
        // Базовые данные товара
        $data = [
            'product' => $product['header'] ?? '',
            'product_code' => $product['articul'] ?? '',
            'status' => $product['enabled'] ? 'A' : 'D',
            'price' => (float)($product['price'] ?? 0),
            'amount' => 1000, // По умолчанию большое количество
            'weight' => (float)($product['weight'] ?? 0),
            'category_ids' => $categoryIds,
            'main_category' => reset($categoryIds), // Первая категория как основная
            'company_id' => $companyId,
            'storefront_id' => $storefrontId
            // УДАЛЯЕМ: PIM поля не поддерживаются CS-Cart API
            // 'pim_sync_uid' => $product['syncUid'] ?? '',
            // 'pim_id' => $product['id'] ?? ''
        ];
        
        // Добавляем описания если есть
        if (!empty($product['content'])) {
            $data['full_description'] = $product['content'];
        }
        
        if (!empty($product['description'])) {
            $data['short_description'] = $product['description'];
        }
        
        // Штрихкод
        if (!empty($product['barCode'])) {
            $data['product_code'] = $product['barCode'];
        }
        
        // Размеры
        if (!empty($product['width'])) {
            $data['width'] = (float)$product['width'];
        }
        if (!empty($product['height'])) {
            $data['height'] = (float)$product['height'];
        }
        if (!empty($product['length'])) {
            $data['length'] = (float)$product['length'];
        }
        
        return $data;
    }
    
    /**
     * Создает новый товар в CS-Cart
     *
     * @param array $productData Данные товара
     * @return int|null ID созданного товара или null при ошибке
     */
    private function createCsCartProduct(array $productData): ?int
    {
        try {
            $response = $this->csCartClient->updateProduct($productData);
            
            if (isset($response['product_id'])) {
                return (int)$response['product_id'];
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger?->log("Ошибка при создании товара: " . $e->getMessage(), 'error');
            return null;
        }
    }
    
    /**
     * Обновляет существующий товар в CS-Cart
     *
     * @param int $productId ID товара в CS-Cart
     * @param array $productData Данные товара
     * @return bool Успешность обновления
     */
    private function updateCsCartProduct(int $productId, array $productData): bool
    {
        try {
            $response = $this->csCartClient->updateProduct($productData, $productId);
            
            return isset($response['product_id']);
        } catch (Exception $e) {
            $this->logger?->log("Ошибка при обновлении товара ID $productId: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Загружает маппинг товаров PIM -> CS-Cart из БД
     *
     * @param string $catalogId ID каталога
     * @param int|string $companyId ID компании
     * @return array Карта маппинга pim_id => cscart_product_id
     */
    private function buildProductPimIdMap(string $catalogId, $companyId, ?int $storefrontId = null): array
    {
        $where_conditions = ["catalog_id = ?s", "company_id = ?i"];
        $params = [$catalogId, $companyId];
        
        if ($storefrontId !== null) {
            $where_conditions[] = "storefront_id = ?i";
            $params[] = $storefrontId;
        }
        
        $sql = "SELECT pim_id, cscart_product_id FROM ?:pim_sync_product_map WHERE " . implode(' AND ', $where_conditions);
        
        $data = db_get_hash_single_array($sql, ['pim_id', 'cscart_product_id'], ...$params);
        
        $this->logger?->log("Загружен маппинг товаров PIM->CS-Cart: " . count($data) . " записей", 'debug');
        
        return $data;
    }
    
    /**
     * Сохраняет маппинг товара PIM -> CS-Cart в БД
     *
     * @param array $product Товар из PIM
     * @param int $csCartProductId ID товара в CS-Cart
     * @param string $catalogId ID каталога
     * @param int|string $companyId ID компании
     * @return bool
     */
    private function saveProductMapping(array $product, int $csCartProductId, string $catalogId, $companyId): bool
    {
        $data = [
            'pim_id' => $product['id'],
            'pim_sync_uid' => $product['syncUid'],
            'cscart_product_id' => $csCartProductId,
            'catalog_id' => $catalogId,
            'company_id' => $companyId,
            'timestamp' => TIME
        ];
        
        $result = db_replace_into('pim_sync_product_map', $data);
        
        if ($result) {
            $this->logger?->log("Сохранен маппинг товара PIM ID {$product['id']} -> CS-Cart ID $csCartProductId", 'debug');
        } else {
            $this->logger?->log("Ошибка сохранения маппинга товара PIM ID {$product['id']}", 'error');
        }
        
        return (bool)$result;
    }

    /**
     * Подсчитывает общее количество категорий в дереве
     *
     * @param array $category Категория
     * @return int Общее количество категорий
     */
    private function countCategoriesRecursive(array $category): int
    {
        $count = 1; // Текущая категория
        
        if (!empty($category['children'])) {
            foreach ($category['children'] as $child) {
                $count += $this->countCategoriesRecursive($child);
            }
        }
        
        return $count;
    }

    /**
     * Получает все категории из CS-Cart для указанной компании
     *
     * @param int|string $companyId ID компании в CS-Cart
     * @param int|null $storefrontId ID витрины
     * @return array Массив категорий
     */
    private function getCsCartCategories($companyId, ?int $storefrontId = null): array
    {
        try {
            $params = [
                'company_id' => $companyId,
                'items_per_page' => 250, // Максимальное количество элементов за запрос
                'get_all' => true // Запрашиваем все поля категорий
            ];
            
            if ($storefrontId !== null) {
                $params['storefront_id'] = $storefrontId;
            }
            
            $this->logger?->log("Запрос существующих категорий из CS-Cart для company_id: $companyId", 'debug');
            
            $response = $this->csCartClient->makeRequest('/api/2.0/categories', 'GET', $params);
            
            // Рекурсивное получение всех категорий через пагинацию
            $allCategories = $response['categories'] ?? [];
            $totalPages = ceil(($response['params']['total_items'] ?? 0) / ($response['params']['items_per_page'] ?? 50));
            
            for ($page = 2; $page <= $totalPages; $page++) {
                $params['page'] = $page;
                $nextPageResponse = $this->csCartClient->makeRequest('/api/2.0/categories', 'GET', $params);
                if (!empty($nextPageResponse['categories'])) {
                    $allCategories = array_merge($allCategories, $nextPageResponse['categories']);
                }
            }
            
            $this->logger?->log("Получено " . count($allCategories) . " категорий из CS-Cart", 'debug');
            
            return $allCategories;
        } catch (Exception $e) {
            $this->logger?->log("Ошибка получения категорий из CS-Cart: " . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * Строит карту соответствия PIM ID к CS-Cart ID категорий из БД
     *
     * @param string $catalogId ID каталога в PIM
     * @param int|string $companyId ID компании
     * @param int|null $storefrontId ID витрины
     * @return array Карта соответствия pim_id => cscart_category_id
     */
    private function buildPimIdToCsCartIdMap(string $catalogId, $companyId, ?int $storefrontId = null): array
    {
        $condition = "catalog_id = ?s AND company_id = ?i";
        $params = [$catalogId, $companyId];
        
        if ($storefrontId !== null) {
            $condition .= " AND storefront_id = ?i";
            $params[] = $storefrontId;
        }
        
        $data = db_get_hash_single_array(
            "SELECT pim_id, cscart_category_id FROM ?:pim_sync_category_map WHERE $condition",
            ['pim_id', 'cscart_category_id'],
            ...$params
        );
        
        $this->logger?->log("Загружена карта маппинга PIM->CS-Cart для каталога $catalogId: " . count($data) . " записей", 'debug');
        
        return $data;
    }
    
    /**
     * Сохраняет маппинг категории PIM -> CS-Cart в БД
     *
     * @param array $category Категория из PIM
     * @param int $csCartCategoryId ID категории в CS-Cart
     * @param string $catalogId ID каталога в PIM
     * @param int|string $companyId ID компании
     * @param int|null $storefrontId ID витрины
     * @return bool
     */
    private function saveCategoryMapping(array $category, int $csCartCategoryId, string $catalogId, $companyId, ?int $storefrontId = null): bool
    {
        $data = [
            'pim_id' => $category['id'],
            'pim_sync_uid' => $category['syncUid'],
            'cscart_category_id' => $csCartCategoryId,
            'catalog_id' => $catalogId,
            'company_id' => $companyId,
            'storefront_id' => $storefrontId ?: 0,
            'timestamp' => TIME
        ];
        
        $result = db_replace_into('pim_sync_category_map', $data);
        
        if ($result) {
            $this->logger?->log("Сохранен маппинг категории PIM ID {$category['id']} -> CS-Cart ID $csCartCategoryId", 'debug');
        } else {
            $this->logger?->log("Ошибка сохранения маппинга категории PIM ID {$category['id']}", 'error');
        }
        
        return (bool)$result;
    }

    /**
     * Обрабатывает дерево категорий и синхронизирует их с CS-Cart
     *
     * @param array $category Категория из PIM
     * @param int $parentId ID родительской категории в CS-Cart (0 для корневых)
     * @param int|string $companyId ID компании в CS-Cart
     * @param int|null $storefrontId ID витрины в CS-Cart
     * @param string $catalogId ID каталога в PIM
     * @param array $pimIdMap Карта соответствия PIM ID => CS-Cart ID
     * @return array Результат синхронизации
     */
    private function processCategoryTree(array $category, int $parentId, $companyId, ?int $storefrontId, string $catalogId, array &$pimIdMap): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'details' => []
        ];

        try {
            // Проверяем, нужно ли пропустить эту категорию
            // Пропускаем только если это корневая категория каталога (level = 2 и parentId = 0)
            $shouldSkipCategory = false;
            if (isset($category['level']) && $category['level'] == 2 && $parentId == 0) {
                $shouldSkipCategory = true;
                $this->logger?->log("Пропускаем корневую категорию каталога: {$category['header']}", 'info');
            }

            $csCartCategoryId = null;
            
            if (!$shouldSkipCategory) {
                // Проверяем существование категории по PIM ID в таблице маппинга
                $existingCategoryId = isset($pimIdMap[$category['id']]) ? $pimIdMap[$category['id']] : null;
                
                // Формируем данные для CS-Cart
                $categoryData = [
                    'category' => $category['header'],
                    'company_id' => $companyId,  // Используем переданный company_id
                    'status' => $category['enabled'] ? 'A' : 'D',
                    'position' => $category['pos'] ?? 0,
                    'parent_id' => $parentId,
                    'description' => $category['content'] ?? '',
                    'meta_keywords' => $category['htKeywords'] ?? '',
                    'meta_description' => $category['htDesc'] ?? '',
                    'page_title' => $category['htHead'] ?? ''
                    // УДАЛЯЕМ: PIM поля не поддерживаются CS-Cart API  
                    // self::PIM_SYNC_UID_FIELD => $category['syncUid'],
                    // self::PIM_ID_FIELD => $category['id']
                ];
                
                // Если есть storefront_id, добавляем его (опционально для CS-Cart)
                if ($storefrontId) {
                    $categoryData['storefront_id'] = $storefrontId;
                }
                
                $action = '';
                
                if ($existingCategoryId) {
                    // Обновляем существующую категорию
                    $csCartCategoryId = $existingCategoryId;
                    $this->logger?->log("Обновление существующей категории: {$category['header']} (ID: $csCartCategoryId)", 'info');
                    
                    try {
                        $updateResult = $this->updateCsCartCategory($csCartCategoryId, $categoryData);
                        
                        if ($updateResult) {
                            $result['updated']++;
                            $action = 'updated';
                            $this->logger?->log("Обновлена категория: {$category['header']} (ID: $csCartCategoryId)", 'info');
                            
                            // Сохраняем маппинг в БД
                            $this->saveCategoryMapping($category, $csCartCategoryId, $catalogId, $companyId, $storefrontId);
                            
                            // Обновляем карту маппинга в памяти
                            $pimIdMap[$category['id']] = $csCartCategoryId;
                        } else {
                            $result['failed']++;
                            $action = 'update_failed';
                            $this->logger?->log("Ошибка при обновлении категории {$category['header']} (ID: $csCartCategoryId)", 'error');
                        }
                    } catch (Exception $e) {
                        $result['failed']++;
                        $action = 'update_failed';
                        $this->logger?->log("Ошибка при обновлении категории {$category['header']} (ID: $csCartCategoryId): " . $e->getMessage(), 'error');
                    }
                } else {
                    // Создаем новую категорию через API
                    $this->logger?->log("Создание новой категории: {$category['header']}", 'info');
                    
                    try {
                        $csCartCategoryId = $this->createCsCartCategory($categoryData);
                        
                        if ($csCartCategoryId) {
                            $result['created']++;
                            $action = 'created';
                            $this->logger?->log("Создана категория: {$category['header']} (ID: $csCartCategoryId)", 'info');
                            
                            // Сохраняем маппинг в БД
                            $this->saveCategoryMapping($category, $csCartCategoryId, $catalogId, $companyId, $storefrontId);
                            
                            // Обновляем карту маппинга в памяти
                            $pimIdMap[$category['id']] = $csCartCategoryId;
                        } else {
                            $result['failed']++;
                            $action = 'create_failed';
                            $this->logger?->log("Не удалось создать категорию: {$category['header']}", 'warning');
                        }
                    } catch (Exception $e) {
                        $result['failed']++;
                        $action = 'create_failed';
                        $this->logger?->log("Ошибка при создании категории {$category['header']}: " . $e->getMessage(), 'error');
                    }
                }
                
                // Добавляем информацию о результате
                if ($action) {
                    $result['details'][] = [
                        'action' => $action,
                        'pim_id' => $category['id'],
                        'pim_header' => $category['header'],
                        'cscart_id' => $csCartCategoryId,
                        'parent_id' => $parentId
                    ];
                }
            }
            
            // Рекурсивно обрабатываем дочерние категории
            if (!empty($category['children'])) {
                foreach ($category['children'] as $childCategory) {
                    // Определяем правильный parentId для дочерней категории
                    $childParentId = $shouldSkipCategory ? $parentId : $csCartCategoryId;
                    
                    $childResult = $this->processCategoryTree(
                        $childCategory, 
                        $childParentId,
                        $companyId,
                        $storefrontId,
                        $catalogId,
                        $pimIdMap
                    );
                    
                    // Обновляем общий результат
                    $result['created'] += $childResult['created'];
                    $result['updated'] += $childResult['updated'];
                    $result['failed'] += $childResult['failed'];
                    $result['details'] = array_merge($result['details'], $childResult['details']);
                }
            }

        } catch (Exception $e) {
            $this->logger?->log("Ошибка при обработке категории {$category['header']}: " . $e->getMessage(), 'error');
            $result['failed']++;
            $result['details'][] = [
                'action' => 'error',
                'pim_id' => $category['id'],
                'pim_header' => $category['header'],
                'error' => $e->getMessage()
            ];
        }
        
        return $result;
    }

    /**
     * Создает новую категорию в CS-Cart через REST API
     *
     * @param array $categoryData Данные категории
     * @return int|null ID созданной категории или null при ошибке
     */
    private function createCsCartCategory(array $categoryData): ?int
    {
        try {
            // Валидация обязательных полей
            if (empty($categoryData['category'])) {
                throw new Exception('Отсутствует обязательное поле "category"');
            }
            
            // Проверяем, что parent_id существует (если указан)
            if (!empty($categoryData['parent_id']) && $categoryData['parent_id'] > 0) {
                if (!$this->categoryExists($categoryData['parent_id'])) {
                    throw new Exception("Родительская категория с ID {$categoryData['parent_id']} не найдена");
                }
            }
            
            $this->logger?->log("Создание категории через CS-Cart API: {$categoryData['category']}", 'debug');
            $this->logger?->log("Данные категории: " . json_encode($categoryData, JSON_UNESCAPED_UNICODE), 'debug');
            
            $response = $this->csCartClient->makeRequest('/api/2.0/categories', 'POST', $categoryData);
            
            if (isset($response['category_id'])) {
                $categoryId = (int)$response['category_id'];
                $this->logger?->log("CS-Cart API вернул ID созданной категории: {$categoryId}", 'debug');
                
                // Проверяем, что категория действительно создана
                if ($this->categoryExists($categoryId)) {
                    return $categoryId;
                } else {
                    throw new Exception("Категория была создана, но не найдена при проверке");
                }
            }
            
            $this->logger?->log("CS-Cart API не вернул ID категории при создании", 'warning');
            $this->logger?->log("Ответ API: " . json_encode($response, JSON_UNESCAPED_UNICODE), 'debug');
            return null;
        } catch (Exception $e) {
            $this->logger?->log("Ошибка при создании категории {$categoryData['category']}: " . $e->getMessage(), 'error');
            return null;
        }
    }

    /**
     * Проверяет существование категории в CS-Cart
     *
     * @param int $categoryId ID категории
     * @return bool true если категория существует
     */
    private function categoryExists(int $categoryId): bool
    {
        try {
            $response = $this->csCartClient->makeRequest("/api/2.0/categories/{$categoryId}", 'GET');
            return isset($response['category_id']);
        } catch (Exception $e) {
            $this->logger?->log("Ошибка при проверке существования категории ID {$categoryId}: " . $e->getMessage(), 'debug');
            return false;
        }
    }

    /**
     * Обновляет существующую категорию в CS-Cart через REST API
     *
     * @param int $categoryId ID категории в CS-Cart
     * @param array $categoryData Данные категории
     * @return bool Успешность обновления
     */
    private function updateCsCartCategory(int $categoryId, array $categoryData): bool
    {
        try {
            // Проверяем, что категория существует
            if (!$this->categoryExists($categoryId)) {
                throw new Exception("Категория с ID {$categoryId} не найдена");
            }
            
            // Валидация обязательных полей
            if (empty($categoryData['category'])) {
                throw new Exception('Отсутствует обязательное поле "category"');
            }
            
            // Проверяем, что parent_id существует (если указан)
            if (!empty($categoryData['parent_id']) && $categoryData['parent_id'] > 0) {
                if (!$this->categoryExists($categoryData['parent_id'])) {
                    throw new Exception("Родительская категория с ID {$categoryData['parent_id']} не найдена");
                }
            }
            
            $this->logger?->log("Обновление категории через CS-Cart API, ID: $categoryId", 'debug');
            $this->logger?->log("Данные для обновления: " . json_encode($categoryData, JSON_UNESCAPED_UNICODE), 'debug');
            
            $response = $this->csCartClient->makeRequest("/api/2.0/categories/{$categoryId}", 'PUT', $categoryData);
            
            $success = isset($response['category_id']);
            if ($success) {
                $this->logger?->log("Категория успешно обновлена в CS-Cart, ID: $categoryId", 'debug');
            } else {
                $this->logger?->log("CS-Cart API не вернул ID категории при обновлении", 'warning');
                $this->logger?->log("Ответ API: " . json_encode($response, JSON_UNESCAPED_UNICODE), 'debug');
            }
            
            return $success;
        } catch (Exception $e) {
            $this->logger?->log("Ошибка при обновлении категории ID {$categoryId}: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Генерирует SEO-имя для категории на основе её названия
     *
     * @param string $name Название категории
     * @return string SEO-имя
     */
    private function generateSeoName(string $name): string
    {
        // Транслитерация русского текста
        $name = $this->transliterate($name);
        
        // Заменяем все, кроме букв, цифр и дефисов на дефисы
        $name = preg_replace('/[^a-z0-9-]/', '-', strtolower($name));
        
        // Убираем повторяющиеся дефисы
        $name = preg_replace('/-+/', '-', $name);
        
        // Убираем дефисы в начале и конце
        $name = trim($name, '-');
        
        return $name;
    }

    /**
     * Транслитерирует русский текст в латиницу
     *
     * @param string $text Исходный текст
     * @return string Транслитерированный текст
     */
    private function transliterate(string $text): string
    {
        $translit = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
            'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E',
            'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K',
            'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R',
            'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts',
            'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
        ];
        
        return strtr($text, $translit);
    }

    /**
     * Синхронизирует каталог продуктов
     *
     * @param string $catalogId ID каталога в PIM
     * @param string $syncType Тип синхронизации (full|delta)
     * @param int $days Количество дней для delta-синхронизации
     * @return array Результат синхронизации
     */
    public function syncCatalog(string $catalogId, string $syncType = 'full', int $days = 1): array
    {
        $result = [
            'status' => 'started',
            'type' => $syncType,
            'catalog_id' => $catalogId,
            'categories' => [
                'total' => 0,
                'created' => 0,
                'updated' => 0,
                'failed' => 0
            ],
            'products' => [
                'total' => 0,
                'created' => 0,
                'updated' => 0,
                'failed' => 0
            ],
            'start_time' => date('Y-m-d H:i:s'),
            'end_time' => null,
            'error' => null
        ];
        
        try {
            $this->logger?->log("Начало синхронизации каталога ID: $catalogId, тип: $syncType", 'info');
            
            // Определяем целевую витрину для каталога
            $storefrontConfig = $this->getTargetStorefrontForCatalog($catalogId);
            $targetCompanyId = $storefrontConfig['company_id'];
            $targetStorefrontId = $storefrontConfig['storefront_id'];
            
            // 1. Синхронизация категорий
            $this->logger?->log("Начало синхронизации категорий из PIM", 'info');
            $categoriesResult = $this->syncCategories($catalogId, $targetCompanyId);
            
            $result['categories'] = [
                'total' => $categoriesResult['total'],
                'created' => $categoriesResult['created'],
                'updated' => $categoriesResult['updated'],
                'failed' => $categoriesResult['failed']
            ];
            
            // 2. Синхронизация продуктов
            $this->logger?->log("Начало синхронизации продуктов из PIM", 'info');
            
            if ($syncType === 'delta') {
                $this->logger?->log("Выполняется дельта-синхронизация за последние $days дней", 'info');
                $products = $this->pimClient->getChangedProducts($catalogId, $days);
            } else {
                $products = $this->pimClient->getProducts($catalogId);
            }
            
            if (!empty($products)) {
                $result['products']['total'] = count($products);
                $this->logger?->log("Получено продуктов из PIM: " . count($products), 'info');
                
                // Синхронизируем продукты
                $productsResult = $this->syncProducts($products, $catalogId, $targetCompanyId, $targetStorefrontId);
                
                $result['products'] = [
                    'total' => $productsResult['total'],
                    'created' => $productsResult['created'],
                    'updated' => $productsResult['updated'],
                    'failed' => $productsResult['failed']
                ];
            }
            
            $result['status'] = 'completed';
            $result['end_time'] = date('Y-m-d H:i:s');
            $this->logger?->log("Синхронизация завершена успешно", 'info');
            
        } catch (ApiAuthException $e) {
            $this->logger?->log("Ошибка авторизации API: " . $e->getMessage(), 'error');
            $result['status'] = 'failed';
            $result['error'] = "Ошибка авторизации: " . $e->getMessage();
        } catch (Exception $e) {
            $this->logger?->log("Ошибка синхронизации: " . $e->getMessage(), 'error');
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
        }
        
        $result['end_time'] = date('Y-m-d H:i:s');
        return $result;
    }
    
    /**
     * Проверяет соединение с обоими API
     *
     * @return array Результат проверки соединения
     */
    public function testConnections(): array
    {
        $result = [
            'pim' => [
                'success' => false,
                'error' => null
            ],
            'cs_cart' => [
                'success' => false,
                'error' => null
            ]
        ];
        
        try {
            $this->logger?->log("Проверка соединения с PIM API", 'debug');
            $result['pim']['success'] = $this->pimClient->testConnection();
            if (!$result['pim']['success']) {
                $result['pim']['error'] = 'Не удалось установить соединение с PIM API';
            }
        } catch (Exception $e) {
            $this->logger?->log("Ошибка соединения с PIM API: " . $e->getMessage(), 'error');
            $result['pim']['error'] = $e->getMessage();
        }
        
        try {
            $this->logger?->log("Проверка соединения с CS-Cart API", 'debug');
            $result['cs_cart']['success'] = $this->csCartClient->testConnection();
            if (!$result['cs_cart']['success']) {
                $result['cs_cart']['error'] = 'Не удалось установить соединение с CS-Cart API';
            }
        } catch (Exception $e) {
            $this->logger?->log("Ошибка соединения с CS-Cart API: " . $e->getMessage(), 'error');
            $result['cs_cart']['error'] = $e->getMessage();
        }
        
        return $result;
    }
} 
