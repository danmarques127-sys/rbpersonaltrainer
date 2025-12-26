<?php
// create_invite.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['admin']);
$pdo = getPDO();

$successMsg = '';
$errorMsg   = '';
$inviteLink = '';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrf_token, $csrf)) {
        $errorMsg = 'Requisição inválida (CSRF).';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $role  = trim((string)($_POST['role'] ?? 'client'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Email inválido.';
        } elseif (!in_array($role, ['client', 'pro'], true)) {
            $errorMsg = 'Role inválida.';
        } else {
            try {
                // Verifica convite ativo (não usado e não expirado)
                $checkStmt = $pdo->prepare("
                    SELECT id
                    FROM invites
                    WHERE email = ?
                      AND used_at IS NULL
                      AND expires_at > NOW()
                    LIMIT 1
                ");
                $checkStmt->execute([$email]);

                if ($checkStmt->fetchColumn()) {
                    $errorMsg = 'Já existe um convite ativo para este email.';
                } else {
                    // Gera token e hash (só salva o hash no banco)
                    $token     = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);

                    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

                    $insertStmt = $pdo->prepare("
                        INSERT INTO invites (email, role, token_hash, expires_at)
                        VALUES (?, ?, ?, ?)
                    ");
                    $insertStmt->execute([$email, $role, $tokenHash, $expiresAt]);

                    // Base URL dinâmica (localhost e produção)
                    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                    $scheme  = $isHttps ? 'https' : 'http';
                    $host    = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                    $base    = $scheme . '://' . $host;

                    // Projeto roda na RAIZ (sem /app/ e sem subpasta)
                    $inviteLink = $base . '/register.php?token=' . $token;

                    $successMsg = 'Convite criado com sucesso!';
                }
            } catch (Throwable $e) {
                error_log('Create invite error: ' . $e->getMessage());
                $errorMsg = 'Erro ao criar convite. Tente novamente.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Invite · Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- FAVICONS -->
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
    <link rel="manifest" href="/assets/images/site.webmanifest">
    <meta name="msapplication-TileColor" content="#FF7A00">
    <meta name="msapplication-TileImage" content="/assets/images/mstile-150x150.png">

    <link rel="stylesheet" href="/assets/css/global.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">
</head>
<body>

<!-- HEADER (fora do shell) -->
<header class="rbf1-header">
    <div class="rbf1-inner">
        <a class="rbf1-brand" href="dashboard_admin.php" aria-label="RB Personal Trainer Home">
            <img src="/assets/images/logo.png" alt="RB Personal Trainer Logo">
        </a>

        <button class="rbf1-toggle" id="rbf1-toggle" type="button" aria-label="Toggle menu" aria-controls="rbf1-nav" aria-expanded="false">
            ☰
        </button>

        <nav class="rbf1-nav" id="rbf1-nav" aria-label="Primary navigation">
            <ul class="rbf1-links">
                <li><a href="dashboard_admin.php">Admin Dashboard</a></li>
                <li><a href="create_invite.php" aria-current="page">Create Invite</a></li>

                <li class="mobile-only">
                    <a href="../login.php">Logout</a>
                </li>
            </ul>
        </nav>

        <div class="rbf1-right">
            <a class="rbf1-logout" href="../login.php">Logout</a>
        </div>
    </div>
</header>

<main class="wk-container" style="max-width: 920px; margin: 0 auto;">
    <h1>Criar Convite (Admin)</h1>

    <?php if ($successMsg !== ''): ?>
        <div style="color: green;">
            <?php echo h($successMsg); ?>
            <?php if ($inviteLink !== ''): ?>
                <br><strong>Link:</strong>
                <a href="<?php echo h($inviteLink); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo h($inviteLink); ?>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg !== ''): ?>
        <div style="color: red;"><?php echo h($errorMsg); ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">

        <label>Email do convidado:<br>
            <input type="email" name="email" required>
        </label>
        <br><br>

        <label>Role (client ou pro):<br>
            <select name="role">
                <option value="client">client</option>
                <option value="pro">pro</option>
            </select>
        </label>
        <br><br>

        <button type="submit">Criar convite</button>
    </form>

    <p><a href="dashboard_admin.php">← Voltar para dashboard</a></p>
</main>

<!-- FOOTER (fora do shell) -->
<footer class="rbf1-footer">
    <div class="rbf1-footer-inner">
        <div class="rbf1-footer-brand">
            <img src="/assets/images/logo.png" alt="RB Personal Trainer Logo">
        </div>

        <div class="rbf1-footer-social">
            <a href="#" aria-label="Instagram">
                <img src="/assets/images/instagram.png" alt="Instagram Logo">
            </a>
            <a href="#" aria-label="Facebook">
                <img src="/assets/images/facebook.png" alt="Facebook Logo">
            </a>
            <a href="#" aria-label="LinkedIn">
                <img src="/assets/images/linkedin.png" alt="LinkedIn Logo">
            </a>
        </div>

        <div class="rbf1-footer-copy">
            © <?php echo date('Y'); ?> RB Personal Trainer. All rights reserved.
        </div>
    </div>
</footer>

<script>
  // Mobile menu toggle (mantém IDs exigidos: rbf1-toggle, rbf1-nav)
  (function () {
    const btn = document.getElementById('rbf1-toggle');
    const nav = document.getElementById('rbf1-nav');
    if (!btn || !nav) return;

    btn.addEventListener('click', function () {
      const expanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', String(!expanded));
      nav.classList.toggle('is-open', !expanded);
    });
  })();
</script>

</body>
</html>
