<?php
declare(strict_types=1);

defined('APP_BOOTSTRAPPED') or exit('No direct access');

/**
 * Escape seguro para HTML
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
