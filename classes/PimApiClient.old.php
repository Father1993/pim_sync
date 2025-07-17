<?php

/**
 * @file: PimApiClient.php
 * @description: Клиент для работы с Compo PIM API
 * @dependencies: cURL
 * @created: 2025-07-14
 */

namespace Tygh\Addons\PimSync;

use Exception;

class PimApiClient
{
    private string $api_url;
    private ?string $token = null;
    private int $token_expires = 0;
    private string $login;
    private string $password;

    /**
     * Конструктор
     * @param string $api_url
     * @param string $login
     * @param string $password
     */
    public function __construct($api_url, $login, $password)
    {
        $this->api_url = rtrim($api_url, '/');
        $this->login = $login;
        $this->password = $password;
        $this->authenticate();
    }

    /**
     * Авторизация и получение токена согласно документации API
     * @throws Exception
     */
    private function authenticate(): void
    {
        try {
            // Согласно документации API
            $auth_data = [
                'login' => $this->login,
                'password' => $this->password,
                'remember' => true
            ];
            
            $response = $this->makeRequest('/sign-in/', 'POST', $auth_data, false);
            
            // Проверяем структуру ответа согласно документации
            if ($response && isset($response['success']) && $response['success'] === true) {
                // Проверяем наличие токена в правильной структуре
                if (!isset($response['data']['access']['token'])) {
                    throw new Exception('PIM API authentication failed: token not found in response');
                }
                
                $this->token = $response['data']['access']['token'];
                // Токен действителен 1 час (3600 секунд), устанавливаем с запасом
                $this->token_expires = time() + 3300; // 55 минут
                
                fn_pim_sync_log('Успешная авторизация в PIM API');
                fn_pim_sync_log('Токен получен, срок действия: ' . date('Y-m-d H:i:s', $this->token_expires));
            } else {
                $error_message = 'PIM API authentication failed';
                if (isset($response['message'])) {
                    $error_message .= ': ' . $response['message'];
                }
                throw new Exception($error_message);
            }
        } catch (Exception $e) {
            fn_pim_sync_log('Ошибка авторизации в PIM API: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Выполнить запрос к API
     * @param string $endpoint
     * @param string $method
     * @param mixed $data
     * @param bool $use_auth
     * @return array
     * @throws Exception
     */
    private function makeRequest($endpoint, $method = 'GET', $data = null, $use_auth = true)
    {
        if ($use_auth && (!$this->token || time() >= $this->token_expires)) {
            fn_pim_sync_log('Токен отсутствует или истек, запрашиваем новый');
            $this->authenticate();
        }
        
        $url = $this->api_url . $endpoint;
        fn_pim_sync_log("Выполняем запрос: $method $url", 'debug');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        $headers = [
            'Content-Type: application/json',
            'User-Agent: CS-Cart PIM Sync/1.0',
            'Accept: application/json'
        ];
        
        if ($use_auth && $this->token) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
            fn_pim_sync_log("Добавлен токен авторизации", 'debug');
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            $json_data = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            fn_pim_sync_log("Отправляем данные: " . $json_data, 'debug');
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        fn_pim_sync_log("Ответ: HTTP $http_code, длина: " . strlen($response), 'debug');
        
        if ($response === false) {
            throw new Exception('CURL error: ' . $curl_error);
        }
        if ($http_code !== 200) {
            throw new Exception('API error: HTTP ' . $http_code . ' - ' . $response);
        }
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: ' . json_last_error_msg() . ' - Response: ' . substr($response, 0, 200));
        }
        fn_pim_sync_log("Успешный ответ получен", 'debug');
        return $decoded;
    }

    /**
     * Проверить соединение с API
     * @return bool
     */
    public function testConnection()
    {
        return $this->token !== null;
    }
}
