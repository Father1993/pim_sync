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
    /**
     * Конструктор
     *
     * @param string $message Сообщение об ошибке
     * @param int $code Код ошибки
     * @param Exception|null $previous Предыдущее исключение
     */
    public function __construct($message = "", $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
