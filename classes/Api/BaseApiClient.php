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
use Tygh\Addons\PimSync\Exception\ApiAuthException;

abstract class BaseApiClient implements ClientInterface
{
    protected string $api_url;
    protected array $headers = [];
    protected ?LoggerInterface $logger;

    public function __construct(string $api_url, ?LoggerInterface $logger = null)
    {
        $this->api_url = rtrim($api_url, '/');
        $this->logger = $logger;
        $this->headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: CS-Cart PIM Sync/1.0'
        ];
    }

    public function makeRequest(string $endpoint, string $method = 'GET', ?array $data = null, bool $use_auth = true): array
    {
        $url = $this->api_url . $endpoint;
        $this->logger?->log("API запрос: $method $url", 'debug');
        
        if ($data !== null && $method !== 'GET') {
            $this->logger?->log("Отправляемые данные: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'debug');
        }
        
        $response = $this->sendCurlRequest($url, $method, $data);
        
        // Проверяем пустой ответ
        if (empty($response)) {
            $this->logger?->log("Получен пустой ответ от API: $method $url", 'warning');
            return [];
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = 'JSON decode error: ' . json_last_error_msg() . ' - Response: ' . substr($response, 0, 500);
            $this->logger?->log($errorMsg, 'error');
            throw new Exception($errorMsg);
        }
        
        // Логируем ответ API для диагностики
        $this->logger?->log("API ответ: " . substr(json_encode($decoded, JSON_UNESCAPED_UNICODE), 0, 500) . "...", 'debug');
        
        return $decoded;
    }

    protected function sendCurlRequest(string $url, string $method, ?array $data = null): string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $this->getHeaders(),
        ]);

        $method = strtoupper($method);

        if (in_array($method, ['POST', 'PUT'], true)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
                $this->logger?->log("Тело запроса: " . $json_data, 'debug');
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        $this->logger?->log("API ответ: HTTP $http_code, длина: " . strlen((string)$response), 'debug');
        
        if ($curl_error) {
            $this->logger?->log("CURL ошибка: $curl_error, URL: $url", 'error');
        }
        
        curl_close($ch);

        if ($response === false) {
            throw new Exception('CURL error: ' . $curl_error);
        }
        
        // Обработка HTTP ошибок
        if ($http_code >= 400) {
            $error_message = "API error: HTTP $http_code";
            
            // Пытаемся извлечь сообщение об ошибке из JSON
            $error_details = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($error_details['message'])) {
                $error_message .= " - " . $error_details['message'];
            } elseif (json_last_error() === JSON_ERROR_NONE && isset($error_details['error'])) {
                $error_message .= " - " . $error_details['error'];
            }
            
            $this->logger?->log($error_message, 'error');
            $this->logger?->log("Ответ API с ошибкой: " . substr($response, 0, 1000), 'error');
            
            throw new ApiAuthException(
                $error_message, 
                $http_code, 
                ['url' => $url, 'response' => $response]
            );
        }

        return $response;
    }

    /**
     * Получить HTTP заголовки для запроса
     *
     * @return array
     */
    protected function getHeaders(): array
    {
        return $this->headers;
    }
}
