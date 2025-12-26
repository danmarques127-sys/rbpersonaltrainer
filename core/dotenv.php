<?php
declare(strict_types=1);

/**
 * core/dotenv.php
 * Loader simples e compatível de variáveis de ambiente
 */

defined('APP_BOOTSTRAPPED') or exit('No direct access');

$envPath = __DIR__ . '/.env';

if (!is_file($envPath)) {
    return; // sem .env, segue normal
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($lines === false) {
    return;
}

foreach ($lines as $line) {
    $line = trim($line);

    // Ignora comentários e linhas vazias
    if ($line === '' || substr($line, 0, 1) === '#') {
        continue;
    }

    // Espera KEY=VALUE
    $pos = strpos($line, '=');
    if ($pos === false) {
        continue;
    }

    $key = trim(substr($line, 0, $pos));
    $val = trim(substr($line, $pos + 1));

    if ($key === '') {
        continue;
    }

    // Remove aspas se vier "assim" ou 'assim'
    if (
        strlen($val) >= 2 &&
        (
            ($val[0] === '"' && $val[strlen($val) - 1] === '"') ||
            ($val[0] === "'" && $val[strlen($val) - 1] === "'")
        )
    ) {
        $val = substr($val, 1, -1);
    }

    // Não sobrescreve variáveis já existentes
    if (getenv($key) === false) {
        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
        $_SERVER[$key] = $val;
    }
}
