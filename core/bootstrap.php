<?php
declare(strict_types=1);

/**
 * core/bootstrap.php
 * Bootstrap central da aplicação RB Personal Trainer
 *
 * Responsabilidades:
 * - Flag de boot
 * - Erros (dev / prod)
 * - Sessão segura
 * - Carregar dotenv, config, auth
 */

define('APP_BOOTSTRAPPED', true);

// ============================
// ERROS (DEV / PROD)
// ============================
// APP_DEBUG=true no .env para DEV
$debug = getenv('APP_DEBUG');

if (
    $debug === false ||
    $debug === null ||
    $debug === '' ||
    $debug === '1' ||
    strtolower((string)$debug) === 'true'
) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}

// ============================
// SESSION (segura)
// ============================
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

// ============================
// ENV (.env)
// ============================
require_once __DIR__ . '/dotenv.php';

// ============================
// CONFIG / PDO
// ============================
require_once __DIR__ . '/config.php';

// ============================
// AUTH / ACL
// ============================
require_once __DIR__ . '/auth.php';
