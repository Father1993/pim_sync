<?php

/**
 * @file: CsCartApiClient.php
 * @description: Клиент для работы с CS-Cart REST API
 * @dependencies: cURL
 * @created: 2025-01-14
 */

namespace Tygh\Addons\PimSync;

use Exception;

class CsCartApiClient
{
    private string $api_url;
    private string $email;
    private string $api_key;
    private array $default_headers;

    /**
     * Конструктор
     * @param string $api_url URL API CS-Cart
     * @param string $email Email пользователя
     * @param string $api_key API ключ пользователя
     */
    public function __construct($api_url, $email, $api_key)
    {
        $this->api_url = rtrim($api_url, '/');
        $this->email = $email;
        $this->api_key = $api_key;
        
        // Формируем Basic HTTP авторизацию
        $auth_string = base64_encode($this->email . ':' . $this->api_key);
        
        $this->default_headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . $auth_string,
            'User-Agent: CS-Cart PIM Sync/1.0'
        ];
        
        fn_pim_sync_log('CsCartApiClient инициализирован для: ' . $this->api_url);
    }

    /**
     * Проверить соединение с API
     * @return bool
     */
    public function testConnection()
    {
        try {
            $response = $this->makeRequest('/api/2.0/version', 'GET');
            // Эндпоинт version возвращает {"Version": "2.1"}
            return isset($response['Version']) && !empty($response['Version']);
        } catch (Exception $e) {
            fn_pim_sync_log('Ошибка тестирования соединения с CS-Cart API: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Выполнить запрос к API
     * @param string $endpoint
     * @param string $method
     * @param mixed $data
     * @return array
     * @throws Exception
     */
    public function makeRequest($endpoint, $method = 'GET', $data = null)
    {
        $url = $this->api_url . $endpoint;
        fn_pim_sync_log("CS-Cart API запрос: $method $url", 'debug');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->default_headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                $json_data = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
                fn_pim_sync_log("Отправляем данные: " . $json_data, 'debug');
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data !== null) {
                $json_data = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
                fn_pim_sync_log("Отправляем данные: " . $json_data, 'debug');
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        fn_pim_sync_log("CS-Cart API ответ: HTTP $http_code, длина: " . strlen($response), 'debug');
        
        if ($response === false) {
            throw new Exception('CURL error: ' . $curl_error);
        }
        
        // Проверяем, является ли ответ HTML (ошибка PHP)
        if (strpos($response, '<!DOCTYPE html>') !== false || strpos($response, '<html') !== false) {
            // Извлекаем сообщение об ошибке из HTML
            $error_message = 'Ошибка на стороне сервера';
            if (preg_match('/<h3>Message<\/h3>.*?<p[^>]*>(.*?)<\/p>/is', $response, $matches)) {
                $error_message = strip_tags($matches[1]);
            }
            throw new Exception('CS-Cart API error: ' . $error_message . ' (HTML response)');
        }
        
        if ($http_code >= 400) {
            throw new Exception('CS-Cart API error: HTTP ' . $http_code . ' - ' . $response);
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: ' . json_last_error_msg() . ' - Response: ' . substr($response, 0, 200) . '...');
        }
        
        fn_pim_sync_log("CS-Cart API успешный ответ получен", 'debug');
        return $decoded;
    }

}
