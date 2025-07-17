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
            $this->logger?->log("Получение категорий из PIM", 'debug');
            $categories = $this->pimClient->getCategories($catalogId);
            
            if (!empty($categories)) {
                $result['categories']['total'] = count($categories);
                $this->logger?->log("Получено категорий из PIM: " . count($categories), 'info');
                
                // Здесь должен быть код для обработки категорий
                // ...
            }
            
            // 2. Синхронизация продуктов
            $this->logger?->log("Получение продуктов из PIM", 'debug');
            
            if ($syncType === 'delta') {
                $this->logger?->log("Выполняется дельта-синхронизация за последние $days дней", 'info');
                $products = $this->pimClient->getChangedProducts($catalogId, $days);
            } else {
                $products = $this->pimClient->getProducts($catalogId);
            }
            
            if (!empty($products)) {
                $result['products']['total'] = count($products);
                $this->logger?->log("Получено продуктов из PIM: " . count($products), 'info');
                
                // Здесь должен быть код для обработки продуктов
                // ...
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
