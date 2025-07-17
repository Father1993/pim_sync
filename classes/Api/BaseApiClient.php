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
        $response = $this->sendCurlRequest($url, $method, $data);
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: ' . json_last_error_msg() . ' - Response: ' . substr($response, 0, 200));
        }
        return $decoded;
    }

    protected function sendCurlRequest(string $url, string $method, mixed $data = null): string
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
            CURLOPT_HTTPHEADER => $this->headers,
        ]);

        $method = strtoupper($method);

        if (in_array($method, ['POST', 'PUT'], true)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                $json_data = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
                $this->logger?->log("Отправляемые данные: " . $json_data, 'debug');
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $this->logger?->log("API ответ: HTTP $http_code, длина: " . strlen((string)$response), 'debug');

        if ($response === false) {
            throw new Exception('CURL error: ' . $curl_error);
        }
        if ($http_code >= 400) {
            throw new ApiAuthException(
                "API error: HTTP $http_code", 
                $http_code, 
                ['url' => $url, 'response' => $response]
            );
        }

        return $response;
    }
}
