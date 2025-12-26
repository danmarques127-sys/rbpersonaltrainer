<?php
declare(strict_types=1);
/**
 * core/auth.php
 * Centraliza funções de autenticação / autorização
 *
 * ✅ NÃO inicia sessão aqui (bootstrap já inicia)
 * ✅ NÃO cria $pdo aqui (cada página pega $pdo = getPDO() quando precisar)
 * ✅ Redirects sempre absolutos a partir da raiz (evita path quebrado)
 * ✅ Protege contra open redirect (não aceita URL externa)
 */

defined('APP_BOOTSTRAPPED') or exit('No direct access');

/**
 * Redirect helper
 * - Só aceita caminhos internos (começando com "/")
 * - Se vier algo suspeito, cai no login
 */
function redirect(string $path): void
{
    $path = trim($path);

    // bloqueia URL absoluta externa (open redirect)
    if (preg_match('~^https?://~i', $path)) {
        $path = '/login.php';
    }

    // garante path absoluto interno
    if ($path === '' || $path[0] !== '/') {
        $path = '/login.php';
    }

    header('Location: ' . $path);
    exit();
}

/**
 * Exige login
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('/login.php');
    }
}

/**
 * Exige role
 * Ex: require_role(['admin','pro']);
 */
function require_role(array $roles): void
{
    $current = $_SESSION['role'] ?? null;

    if (!is_string($current) || $current === '') {
        redirect('/login.php');
    }

    if (!in_array($current, $roles, true)) {
        go_to_dashboard();
    }
}

/**
 * Vai para o dashboard correto baseado na role atual
 */
function go_to_dashboard(): void
{
    $role = $_SESSION['role'] ?? null;

    if (!is_string($role) || $role === '') {
        redirect('/login.php');
    }

    switch ($role) {
        case 'admin':
            redirect('/dashboards/dashboard_admin.php');

        case 'pro':
        case 'trainer':
            redirect('/dashboards/dashboard_pro.php');

        case 'user':
        case 'client':
        default:
            redirect('/dashboards/dashboard_client.php');
    }
}
