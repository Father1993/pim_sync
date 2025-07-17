<?php
/**
 * PIM Sync
 *
 * @package Tygh\Addons\PimSync
 * @author Andrej Spinej
 * @copyright (c) 2025, Уровень
 */

namespace Tygh\Addons\PimSync\Api;

interface ClientInterface
{
  public function testConnection(): bool;
  public function makeRequest(string $endpoint, string $method = 'GET', ?array $data = null, bool $use_auth = true): array;
}
