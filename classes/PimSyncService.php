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
    
    /** @var LoggerInterface|null */
    private $logger;
    
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
     * @param LoggerInterface|null $logger Логгер
     */
    public function __construct(
        PimApiClient $pimClient,
        CsCartApiClient $csCartClient,
        int $companyId = 0,
        ?LoggerInterface $logger = null
    ) {
        $this->pimClient = $pimClient;
        $this->csCartClient = $csCartClient;
        $this->companyId = $companyId;
        $this->logger = $logger;
    }
    
    /**
     * Синхронизирует категории из PIM в CS-Cart
     *
     * @param string $catalogId ID каталога в PIM
     * @param int|string $companyId ID компании/продавца в CS-Cart
     * @param int|null $storefrontId ID витрины в CS-Cart (если null, будет использоваться дефолтная)
     * @return array Результат синхронизации категорий
     */
    public function syncCategories(string $catalogId, $companyId, ?int $storefrontId = null): array
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
            
            // Получаем категории из PIM
            $categories = $this->pimClient->getCategories($catalogId);
            
            if (empty($categories)) {
                $this->logger?->log("Категории не найдены в каталоге ID: $catalogId", 'warning');
                return $result;
            }
            
            // Первый элемент - корневая категория каталога
            $rootCategory = $categories[0];
            $result['total'] = $this->countCategoriesRecursive($rootCategory);
            
            // Получаем существующие категории из CS-Cart для поиска соответствий
            $existingCategories = $this->getCsCartCategories($companyId, $storefrontId);
            $syncUidMap = $this->buildSyncUidMap($existingCategories);
            
            // Синхронизируем категории рекурсивно
            $syncResult = $this->processCategoryTree($rootCategory, 0, $companyId, $storefrontId, $syncUidMap);
            
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
     * Строит карту соответствия syncUid к категориям CS-Cart
     *
     * @param array $categories Массив категорий CS-Cart
     * @return array Карта соответствия syncUid => category
     */
    private function buildSyncUidMap(array $categories): array
    {
        $map = [];
        
        foreach ($categories as $category) {
            // Ищем кастомное поле pim_sync_uid в категории
            if (isset($category[self::PIM_SYNC_UID_FIELD])) {
                $syncUid = $category[self::PIM_SYNC_UID_FIELD];
                $map[$syncUid] = $category;
            }
        }
        
        return $map;
    }

    /**
     * Обрабатывает дерево категорий и синхронизирует их с CS-Cart
     *
     * @param array $category Категория из PIM
     * @param int $parentId ID родительской категории в CS-Cart (0 для корневых)
     * @param int|string $companyId ID компании в CS-Cart
     * @param int|null $storefrontId ID витрины в CS-Cart
     * @param array $syncUidMap Карта соответствия syncUid => category
     * @return array Результат синхронизации
     */
    private function processCategoryTree(array $category, int $parentId, $companyId, ?int $storefrontId, array $syncUidMap): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'details' => []
        ];

        try {
            // Пропускаем корневую категорию каталога, если это нужно
            if (isset($category['level']) && $category['level'] <= 2 && empty($parentId)) {
                $this->logger?->log("Пропускаем корневую категорию каталога: {$category['header']}", 'info');
                
                // Рекурсивно обрабатываем только дочерние категории
                if (!empty($category['children'])) {
                    foreach ($category['children'] as $childCategory) {
                        $childResult = $this->processCategoryTree(
                            $childCategory, 
                            0, // для дочерних корневой категории используем 0 как parentId
                            $companyId,
                            $storefrontId,
                            $syncUidMap
                        );
                        
                        // Обновляем общий результат
                        $result['created'] += $childResult['created'];
                        $result['updated'] += $childResult['updated'];
                        $result['failed'] += $childResult['failed'];
                        $result['details'] = array_merge($result['details'], $childResult['details']);
                    }
                }
                
                return $result;
            }
            
            // Проверяем существование категории по syncUid
            $existingCategory = isset($syncUidMap[$category['syncUid']]) ? $syncUidMap[$category['syncUid']] : null;
            
            // Формируем данные для CS-Cart
            $categoryData = [
                'category' => $category['header'],
                'company_id' => $companyId,
                'status' => $category['enabled'] ? 'A' : 'D',
                'position' => $category['pos'] ?? 0,
                'description' => $category['content'] ?? '',
                'meta_keywords' => $category['htKeywords'] ?? '',
                'meta_description' => $category['htDesc'] ?? '',
                'page_title' => $category['htHead'] ?? '',
                // Добавляем кастомные поля для хранения данных из PIM
                self::PIM_SYNC_UID_FIELD => $category['syncUid'],
                self::PIM_ID_FIELD => $category['id']
            ];
            
            // Если есть storefront_id, добавляем его
            if ($storefrontId !== null) {
                $categoryData['storefront_id'] = $storefrontId;
            }
            
            // Генерируем SEO имя
            $categoryData['seo_name'] = $this->generateSeoName($category['header']);
            
            // Устанавливаем родительскую категорию
            if ($parentId > 0) {
                $categoryData['parent_id'] = $parentId;
            }
            
            $this->logger?->log("Подготовлены данные для категории: {$category['header']}", 'debug');

            $csCartCategoryId = 0;
            $action = '';
            
            if ($existingCategory) {
                // Обновляем существующую категорию
                $csCartCategoryId = (int)$existingCategory['category_id'];
                $this->logger?->log("Найдена существующая категория по syncUid {$category['syncUid']}, CS-Cart ID: $csCartCategoryId", 'info');
                
                // Обновляем категорию через API
                try {
                    $success = $this->updateCsCartCategory($csCartCategoryId, $categoryData);
                    
                    if ($success) {
                        $result['updated']++;
                        $action = 'updated';
                        $this->logger?->log("Обновлена категория: {$category['header']} (ID: $csCartCategoryId)", 'info');
                    } else {
                        $result['failed']++;
                        $action = 'update_failed';
                        $this->logger?->log("Не удалось обновить категорию: {$category['header']} (ID: $csCartCategoryId)", 'warning');
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
                        
                        // Добавляем созданную категорию в карту соответствия
                        $categoryData['category_id'] = $csCartCategoryId;
                        $syncUidMap[$category['syncUid']] = $categoryData;
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
            $result['details'][] = [
                'action' => $action,
                'pim_id' => $category['id'],
                'pim_header' => $category['header'],
                'cscart_id' => $csCartCategoryId,
                'parent_id' => $parentId
            ];
            
            // Рекурсивно обрабатываем дочерние категории
            if (!empty($category['children']) && $csCartCategoryId) {
                foreach ($category['children'] as $childCategory) {
                    $childResult = $this->processCategoryTree(
                        $childCategory, 
                        $csCartCategoryId,
                        $companyId,
                        $storefrontId,
                        $syncUidMap
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
            $this->logger?->log("Создание категории через CS-Cart API: {$categoryData['category']}", 'debug');
            $response = $this->csCartClient->makeRequest('/api/2.0/categories', 'POST', $categoryData);
            
            if (isset($response['category_id'])) {
                $this->logger?->log("CS-Cart API вернул ID созданной категории: {$response['category_id']}", 'debug');
                return (int)$response['category_id'];
            }
            
            $this->logger?->log("CS-Cart API не вернул ID категории при создании", 'warning');
            return null;
        } catch (Exception $e) {
            $this->logger?->log("Ошибка при создании категории {$categoryData['category']}: " . $e->getMessage(), 'error');
            return null;
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
            $this->logger?->log("Обновление категории через CS-Cart API, ID: $categoryId", 'debug');
            $response = $this->csCartClient->makeRequest("/api/2.0/categories/{$categoryId}", 'PUT', $categoryData);
            
            $success = isset($response['category_id']);
            if ($success) {
                $this->logger?->log("Категория успешно обновлена в CS-Cart, ID: $categoryId", 'debug');
            } else {
                $this->logger?->log("CS-Cart API не вернул ID категории при обновлении", 'warning');
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
            
            // 1. Синхронизация категорий
            $this->logger?->log("Начало синхронизации категорий из PIM", 'info');
            $categoriesResult = $this->syncCategories($catalogId, $this->companyId);
            
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
                
                // TODO: Здесь будет код для обработки продуктов
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
