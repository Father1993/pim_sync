<?php
/**
 *  @file: init.php
 *  @description: Инициализация аддона
 *  @dependencies: CS-Cart core
 *  @created: 2025-17-07
 */

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// Функция для безопасной загрузки классов аддона
function fn_pim_sync_autoload_classes() {
    // Определяем базовые пути к классам
    $addon_dir = dirname(__FILE__);
    
    // Классы API
    require_once $addon_dir . '/classes/Api/ClientInterface.php';
    require_once $addon_dir . '/classes/Api/BaseApiClient.php';
    require_once $addon_dir . '/classes/Api/PimApiClient.php';
    require_once $addon_dir . '/classes/Api/CsCartApiClient.php';
    
    // Исключения
    require_once $addon_dir . '/classes/Exception/ApiAuthException.php';
    
    // Utils
    require_once $addon_dir . '/classes/Utils/LoggerInterface.php';
    require_once $addon_dir . '/classes/Utils/Logger.php';
    
    // Основные классы
    require_once $addon_dir . '/classes/PimSyncService.php';
    
    // Логирование успешной загрузки
    fn_log_event('pim_sync', 'debug', ['message' => 'Все базовые классы успешно загружены']);
}

try {
    // Автозагрузка классов аддона через стандартный механизм CS-Cart
    Tygh\Tygh::$app['class_loader']->add('Tygh\Addons\PimSync', __DIR__ . '/classes');
    
    // Явная загрузка классов для обхода возможных проблем автозагрузчика
    fn_pim_sync_autoload_classes();

    // Подключаем файл с функциями-адаптерами для логирования
    if (file_exists(__DIR__ . '/logger.php')) {
        require_once __DIR__ . '/logger.php';
    }

    // Регистрация логгера в контейнере зависимостей
    Tygh\Tygh::$app['addons.pim_sync.logger'] = function() {
        return fn_pim_sync_get_logger();
    };
} catch (Exception $e) {
    // В случае ошибки при инициализации аддона просто записываем в лог системы
    fn_log_event('pim_sync', 'error', ['message' => 'Init error: ' . $e->getMessage()]);
}
