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

// Автозагрузка классов аддона через стандартный механизм CS-Cart
Tygh\Tygh::$app['class_loader']->add('Tygh\Addons\PimSync', __DIR__ . '/classes');

// Подключаем файл с функциями-адаптерами для логирования
require_once __DIR__ . '/logger.php';

// Регистрация логгера в контейнере зависимостей
Tygh\Tygh::$app['addons.pim_sync.logger'] = function() {
    return fn_pim_sync_get_logger();
};
