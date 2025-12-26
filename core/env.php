<?php
// env.php
// Lê variáveis do arquivo .env (mesma pasta deste env.php) e aplica em
// getenv()/$_ENV/$_SERVER para o resto do app.
// ✅ Produção: feito para funcionar ONLINE (Brevo SMTP), sem depender de "local".

declare(strict_types=1);

if (!function_exists('env_parse_file')) {
    function env_parse_file(string $path): array
    {
        if (!is_file($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];

        $data = [];
        foreach ($lines as $line) {
            $line = trim($line);

            // ignora comentários e linhas vazias
            if ($line === '' || str_starts_with($line, '#')) continue;

            // permite "export KEY=VALUE"
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // remove aspas se vier "..."/'...'
            if ($val !== '' && (
                ($val[0] === '"' && substr($val, -1) === '"') ||
                ($val[0] === "'" && substr($val, -1) === "'")
            )) {
                $val = substr($val, 1, -1);
            }

            if ($key !== '') {
                $data[$key] = $val;
            }
        }

        return $data;
    }
}

if (!function_exists('env_apply')) {
    function env_apply(array $vars): void
    {
        foreach ($vars as $key => $val) {
            // não sobrescreve se já existe no ambiente do servidor
            $existing = getenv((string)$key);
            if ($existing !== false && $existing !== '') {
                continue;
            }

            // aplica no ambiente (para getenv)
            putenv($key . '=' . $val);

            // aplica também em $_ENV e $_SERVER (alguns hosts leem por aqui)
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

/**
 * Lê o .env da MESMA pasta do env.php:
 * /core/env.php  -> /core/.env
 */
$envPath = __DIR__ . '/.env';
$raw     = env_parse_file($envPath);

// aplica tudo no ambiente
env_apply($raw);

/**
 * Defaults úteis
 * - Produção por padrão
 * - Debug OFF por padrão (em produção você quase sempre quer 0)
 */
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=production');
    $_ENV['APP_ENV'] = 'production';
    $_SERVER['APP_ENV'] = 'production';
}

if (getenv('APP_DEBUG') === false || getenv('APP_DEBUG') === '') {
    putenv('APP_DEBUG=0');
    $_ENV['APP_DEBUG'] = '0';
    $_SERVER['APP_DEBUG'] = '0';
}

/**
 * ✅ Retorna um array de config pronto
 * Assim você pode fazer: $envConfig = require __DIR__ . '/core/env.php';
 * E também pode usar getenv() no resto do app.
 */
return [
    // app
    'environment' => (string)(getenv('APP_ENV') ?: 'production'),
    'debug'       => (string)(getenv('APP_DEBUG') ?: '0'),

    // SMTP LOCAL (se você quiser manter para dev — não atrapalha produção)
    'mail_local' => [
        'host'     => (string)(getenv('MAIL_LOCAL_HOST') ?: ''),
        'username' => (string)(getenv('MAIL_LOCAL_USER') ?: ''),
        'password' => (string)(getenv('MAIL_LOCAL_PASS') ?: ''),
        'port'     => (int)(getenv('MAIL_LOCAL_PORT') ?: 2525),
    ],

    // SMTP PRODUÇÃO (BREVO)
    'mail_production' => [
        'host'     => (string)(getenv('MAIL_PROD_HOST') ?: ''),
        'username' => (string)(getenv('MAIL_PROD_USER') ?: ''),
        'password' => (string)(getenv('MAIL_PROD_PASS') ?: ''),
        'port'     => (int)(getenv('MAIL_PROD_PORT') ?: 587),
    ],

    // From configurável (recomendado setar no .env em produção)
    // MAIL_FROM_EMAIL=seu-remetente@seudominio.com
    // MAIL_FROM_NAME="RB Personal Trainer"
    'mail_from_email' => (string)(getenv('MAIL_FROM_EMAIL') ?: ''),
    'mail_from_name'  => (string)(getenv('MAIL_FROM_NAME') ?: 'RB Personal Trainer'),

    // Base path opcional (se site estiver em subpasta)
    // APP_BASE_PATH=/rbpersonaltrainer
    'app_base_path'   => (string)(getenv('APP_BASE_PATH') ?: ''),
];
