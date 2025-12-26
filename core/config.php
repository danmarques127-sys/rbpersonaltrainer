<?php
declare(strict_types=1);
/**
 * core/config.php
 * - Timezone
 * - Loads dotenv (optional)
 * - Central getPDO()
 */

defined('APP_BOOTSTRAPPED') or exit('No direct access');

date_default_timezone_set('America/New_York');

/**
 * Carrega variáveis do arquivo core/.env via dotenv.php (produção/dev).
 * - Não quebra se não existir.
 * - Preenche getenv()/$_ENV/$_SERVER.
 */
$dotenvLoader = __DIR__ . '/dotenv.php';
if (is_file($dotenvLoader)) {
    require_once $dotenvLoader;
}

// =========================
// Fallbacks (LOCAL / DEV)
// =========================
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'rb_coaching';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

// =========================
// DEBUG FLAG
// =========================
$debugRaw = getenv('APP_DEBUG');
if ($debugRaw === false || $debugRaw === null || $debugRaw === '') {
    $appDebug = true; // default: true (dev). Em produção, set APP_DEBUG=false no .env
} else {
    $v = strtolower(trim((string)$debugRaw));
    $appDebug = in_array($v, ['1', 'true', 'yes', 'on'], true);
}

// =========================
// PDO CONNECTION
// =========================
function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbHost   = $GLOBALS['dbHost'] ?? '127.0.0.1';
    $dbPort   = $GLOBALS['dbPort'] ?? '3306';
    $dbName   = $GLOBALS['dbName'] ?? '';
    $dbUser   = $GLOBALS['dbUser'] ?? '';
    $dbPass   = $GLOBALS['dbPass'] ?? '';
    $appDebug = (bool)($GLOBALS['appDebug'] ?? false);

    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);

        return $pdo;

    } catch (PDOException $e) {
        error_log('[DB ERROR] ' . $e->getMessage());

        if ($appDebug) {
            die('Database connection error: ' . $e->getMessage());
        }

        die('Database connection error.');
    }
}
