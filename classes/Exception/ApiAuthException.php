<?php
/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync
 * @author Уровень
 * @copyright (c) 2025, Уровень
 */

namespace Tygh\Addons\PimSync\Exception;

use Exception;

/**
 * Исключение при ошибке авторизации в API
 */
class ApiAuthException extends Exception
{
    private array $responseData = [];
    
    public function __construct($message = "", $code = 0, array $responseData = [], Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->responseData = $responseData;
    }
    
    public function getResponseData(): array
    {
        return $this->responseData;
    }
}
