<?php
// trainer_clients.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['pro']);

$pdo = getPDO();

$current_user_id = (int)($_SESSION['user_id'] ?? 0);

// ================================
// CSRF (POST forms)
// ================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

// ================================
// 2) Processar convite (POST)
// ================================
$inviteSuccess = '';
$inviteError   = '';
$inviteLink    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf_token, $postedToken)) {
        $inviteError = 'Invalid request. Please refresh the page and try again.';
    } else {
        $email = trim((string)($_POST['invite_email'] ?? ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $inviteError = 'Please enter a valid email address.';
        } else {
            try {
                // Verifica se já existe convite ativo para este email + coach
                $sqlCheck = "
                    SELECT id, token, expires_at
                    FROM invites
                    WHERE email = ?
                      AND role = 'client'
                      AND coach_id = ?
                      AND used_at IS NULL
                    ORDER BY expires_at DESC
                    LIMIT 1
                ";
                $stmt = $pdo->prepare($sqlCheck);
                $stmt->execute([$email, $current_user_id]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    // Reaproveita o convite já existente
                    $token     = (string)$existing['token'];
                    $expiresAt = (string)$existing['expires_at'];
                } else {
                    // Cria um novo convite
                    $token = bin2hex(random_bytes(32)); // 64 chars
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

                    $sqlInsert = "
                        INSERT INTO invites (email, role, token, coach_id, expires_at)
                        VALUES (?, 'client', ?, ?, ?)
                    ";
                    $stmt = $pdo->prepare($sqlInsert);
                    $stmt->execute([
                        $email,
                        $token,
                        $current_user_id,
                        $expiresAt,
                    ]);
                }

                // Monta o link absoluto para /register_client.php (no ROOT)
                $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $inviteLink = $scheme . '://' . $host . '/register_client.php?token=' . urlencode($token);

                $inviteSuccess = "Invite link generated successfully for <strong>"
                    . htmlspecialchars($email, ENT_QUOTES, 'UTF-8')
                    . "</strong>.<br>Share this link with your client:";
            } catch (Throwable $e) {
                $inviteError = 'Error creating invite. Please try again.';
                // Para debug: // $inviteError = $e->getMessage();
            }
        }
    }
}

// ================================
// 3) Buscar clientes deste coach
// ================================
$sqlClients = "
    SELECT
        base.id,
        base.name,
        base.email,
        base.avatar_url,
        COALESCE(stats.last_plan_created_at, NULL) AS last_plan_created_at,
        COALESCE(stats.plans_count, 0)             AS plans_count
    FROM (
        -- clientes conectados via coach_clients
        SELECT DISTINCT
            u.id,
            u.name,
            u.email,
            u.avatar_url
        FROM coach_clients cc
        JOIN users u ON u.id = cc.client_id
        WHERE cc.coach_id = ?

        UNION

        -- clientes que têm plano criado por este coach
        SELECT DISTINCT
            u2.id,
            u2.name,
            u2.email,
            u2.avatar_url
        FROM workout_plans wp2
        JOIN users u2 ON u2.id = wp2.user_id
        WHERE wp2.created_by = ?
    ) AS base
    LEFT JOIN (
        SELECT
            wp.user_id,
            MAX(wp.created_at)        AS last_plan_created_at,
            COUNT(DISTINCT wp.id)     AS plans_count
        FROM workout_plans wp
        WHERE wp.created_by = ?
        GROUP BY wp.user_id
    ) AS stats
      ON stats.user_id = base.id
    ORDER BY base.name ASC
";

$stmt = $pdo->prepare($sqlClients);
$stmt->execute([$current_user_id, $current_user_id, $current_user_id]);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalClients = count($clients);

// ================================
// 4) Buscar convites deste coach
// ================================
$sqlInvites = "
    SELECT id, email, token, expires_at, used_at
    FROM invites
    WHERE role = 'client'
      AND coach_id = ?
    ORDER BY expires_at DESC
";
$stmt = $pdo->prepare($sqlInvites);
$stmt->execute([$current_user_id]);
$invites = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Coach Clients | RB Personal Trainer | Rafa Breder Coaching</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <!-- FAVICONS -->
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon-16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
    <link rel="manifest" href="/assets/images/site.webmanifest">
    <meta name="msapplication-TileColor" content="#FF7A00">
    <meta name="msapplication-TileImage" content="/assets/images/mstile-150x150.png">

    <!-- CSS base -->
    <link rel="stylesheet" href="/assets/css/global.css">
    <link rel="stylesheet" href="/assets/css/trainer_clients.css"><!-- CSS específico desta página -->
    <link rel="stylesheet" href="/assets/css/header.css">
    <link rel="stylesheet" href="/assets/css/footer.css">
</head>
<body>

<header id="rb-static-header" class="rbf1-header">
    <div class="rbf1-topbar">
        <a href="/" class="rbf1-logo">
            <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
        </a>

        <nav class="rbf1-nav" id="rbf1-nav">
            <ul>
                <li><a href="dashboard_personal.php" class="rbf1-link">Dashboard</a></li>
                <li><a href="personal_profile.php" class="rbf1-link">Profile</a></li>
                <li><a href="trainer_workouts.php" class="rbf1-link">Workouts</a></li>
                <li><a href="trainer_checkins.php" class="rbf1-link">Check-ins</a></li>
                <li><a href="messages.php" class="rbf1-link">Messages</a></li>
                <li><a href="trainer_clients.php" class="rbf1-link rbf1-link-active">Clients</a></li>

                <li class="mobile-only">
                    <a href="../login.php" class="rb-mobile-logout">Logout</a>
                </li>
            </ul>
        </nav>

        <div class="rbf1-right">
            <a href="../login.php" class="rbf1-login">Logout</a>
        </div>

        <button id="rbf1-toggle" class="rbf1-mobile-toggle" aria-label="Toggle navigation">
            ☰
        </button>
    </div>
</header>

<main class="coach-dashboard">
    <div class="coach-shell">

        <section class="wk-container tc-container">

            <div class="tc-header-row">
                <div>
                    <h1 class="tc-title">Clients</h1>
                    <p class="tc-subtitle">
                        See all clients connected to your coaching and send new invite links.
                    </p>
                </div>

                <div class="tc-summary">
                    <div class="tc-summary-pill">
                        <span class="tc-pill-label">Active clients</span>
                        <span class="tc-pill-value"><?php echo (int)$totalClients; ?></span>
                    </div>
                    <div class="tc-summary-pill">
                        <span class="tc-pill-label">Client invites</span>
                        <span class="tc-pill-value"><?php echo (int)count($invites); ?></span>
                    </div>
                </div>
            </div>

            <div class="tc-invite-card">
                <div class="tc-invite-main">
                    <h2 class="tc-invite-title">Invite a new client</h2>
                    <p class="tc-invite-text">
                        Send an invite link so your client can create a connected account.
                    </p>

                    <?php if (!empty($inviteError)): ?>
                        <div class="tc-alert tc-alert-error">
                            <?php echo htmlspecialchars($inviteError, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($inviteSuccess)): ?>
                        <div class="tc-alert tc-alert-success">
                            <p><?php echo $inviteSuccess; ?></p>
                            <?php if (!empty($inviteLink)): ?>
                                <div class="tc-invite-link-box">
                                    <input
                                        type="text"
                                        class="tc-invite-link-input"
                                        readonly
                                        value="<?php echo htmlspecialchars($inviteLink, ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="this.select();"
                                    >
                                    <p class="tc-invite-link-hint">
                                        Copy and send this link via email, WhatsApp or your preferred channel.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="tc-invite-form" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                        <label for="invite_email" class="tc-field-label">Client email</label>
                        <div class="tc-invite-row">
                            <input
                                type="email"
                                id="invite_email"
                                name="invite_email"
                                required
                                class="tc-input"
                                placeholder="client@email.com"
                            >
                            <button type="submit" class="wk-btn-primary tc-invite-button">
                                Generate invite link
                            </button>
                        </div>
                    </form>
                </div>

                <div class="tc-invite-list">
                    <h3 class="tc-invite-subtitle">Recent invites</h3>

                    <?php if (empty($invites)): ?>
                        <p class="tc-invite-empty">
                            No invites created yet.
                        </p>
                    <?php else: ?>
                        <ul class="tc-invite-items">
                            <?php foreach ($invites as $inv): ?>
                                <?php
                                    $isUsed  = !empty($inv['used_at']);
                                    $expires = !empty($inv['expires_at'])
                                        ? date('M d, Y', strtotime((string)$inv['expires_at']))
                                        : '—';
                                ?>
                                <li class="tc-invite-item">
                                    <div class="tc-invite-item-email">
                                        <?php echo htmlspecialchars((string)$inv['email'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="tc-invite-item-meta">
                                        <span>Expires: <?php echo htmlspecialchars((string)$expires, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <span class="tc-invite-status tc-invite-status-<?php echo $isUsed ? 'used' : 'active'; ?>">
                                            <?php echo $isUsed ? 'Used' : 'Active'; ?>
                                        </span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tc-clients-section">
                <h2 class="tc-section-title">Your clients</h2>
                <p class="tc-section-sub">
                    These are clients who already have at least one workout plan created by you.
                </p>

                <?php if (empty($clients)): ?>
                    <p class="wk-empty">
                        You don’t have any clients with active plans yet. Create a plan or send your first invite.
                    </p>
                <?php else: ?>
                    <div class="tc-clients-grid">
                        <?php foreach ($clients as $cl): ?>
                            <?php
                                $clientName = (string)($cl['name'] ?? '');
                                $avatar = !empty($cl['avatar_url']) ? (string)$cl['avatar_url'] : '/assets/images/default-avatar.png';

                                // Se o avatar vier relativo, deixamos como está; se quiser forçar absoluto, padronize no upload.
                                $lastPlanAt = !empty($cl['last_plan_created_at'])
                                    ? date('M d, Y', strtotime((string)$cl['last_plan_created_at']))
                                    : '—';

                                $clientId = (int)($cl['id'] ?? 0);
                                $avatarAlt = $clientName !== ''
                                    ? 'Profile photo of ' . $clientName
                                    : 'Client profile photo';
                            ?>
                            <article class="tc-client-card">
                                <div class="tc-client-header">
                                    <img
                                        src="<?php echo htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="<?php echo htmlspecialchars($avatarAlt, ENT_QUOTES, 'UTF-8'); ?>"
                                        class="tc-client-avatar"
                                    >
                                    <div class="tc-client-main">
                                        <div class="tc-client-name">
                                            <?php echo htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="tc-client-email">
                                            <?php echo htmlspecialchars((string)($cl['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="tc-client-meta">
                                    <span>Plans: <?php echo (int)($cl['plans_count'] ?? 0); ?></span>
                                    <span>Last plan: <?php echo htmlspecialchars((string)$lastPlanAt, ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>

                                <div class="tc-client-actions">
                                    <a
                                        href="trainer_client_workouts.php?client_id=<?php echo $clientId; ?>"
                                        class="wk-btn-primary tc-client-btn"
                                    >
                                        View workouts
                                    </a>
                                    <a
                                        href="progress_gallery.php?client_id=<?php echo $clientId; ?>"
                                        class="wk-btn-secondary tc-client-btn"
                                    >
                                        Progress photos
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </section>

    </div>
</main>

<script>
  (function () {
    const toggle = document.getElementById('rbf1-toggle');
    const nav = document.getElementById('rbf1-nav');

    if (toggle && nav) {
      toggle.addEventListener('click', function () {
        nav.classList.toggle('rbf1-open');
      });
    }
  })();
</script>

<footer class="site-footer">
    <div class="footer-main">
      <div class="footer-col footer-brand">
        <a href="/" class="footer-logo">
          <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
        </a>
        <p class="footer-text">
          RB Personal Trainer offers complete online coaching with customized
          workout plans, fat-loss programs, muscle-building strategies and
          habit coaching. Train with a certified personal trainer and get
          real results at home, in the gym or wherever you are.
        </p>
      </div>

      <div class="footer-col footer-nav">
        <h3 class="footer-heading">Navigate</h3>
        <ul class="footer-links">
          <li><a href="dashboard_personal.php">Dashboard</a></li>
          <li><a href="personal_profile.php">Profile</a></li>
          <li><a href="trainer_workouts.php">Workouts</a></li>
          <li><a href="trainer_checkins.php">Check-ins</a></li>
          <li><a href="messages.php">Messages</a></li>
          <li><a href="trainer_clients.php">Clients</a></li>
        </ul>
      </div>

      <div class="footer-col footer-legal">
        <h3 class="footer-heading">Legal</h3>
        <ul class="footer-legal-list">
          <li><a href="/privacy.html">Privacy Policy</a></li>
          <li><a href="/terms.html">Terms of Use</a></li>
          <li><a href="/cookies.html">Cookie Policy</a></li>
        </ul>
      </div>

      <div class="footer-col footer-contact">
        <h3 class="footer-heading">Contact</h3>

        <div class="footer-contact-block">
          <p class="footer-text footer-contact-text">
            Prefer a direct line to your coach? Reach out and let’s design your
            training strategy together.
          </p>

          <ul class="footer-contact-list">
            <li>
              <span class="footer-contact-label">Email:</span>
              <a href="mailto:rbpersonaltrainer@gmail.com" class="footer-email-link">
                rbpersonaltrainer@gmail.com
              </a>
            </li>
            <li>
              <span class="footer-contact-label">Location:</span>
              Boston, MA · Online clients across the US
            </li>
            <li class="footer-social-row">
              <span class="footer-contact-label">Social:</span>
              <div class="footer-social-icons">
                <a class="social-icon" href="https://www.instagram.com/rbpersonaltrainer" target="_blank" rel="noopener">
                  <img src="/assets/images/instagram.png" alt="Instagram Logo">
                </a>
                <a class="social-icon" href="https://www.facebook.com/rbpersonaltrainer" target="_blank" rel="noopener">
                  <img src="/assets/images/facebook.png" alt="Facebook Logo">
                </a>
                <a class="social-icon" href="https://www.linkedin.com" target="_blank" rel="noopener">
                  <img src="/assets/images/linkedin.png" alt="LinkedIn Logo">
                </a>
              </div>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="footer-bottom">
      <p class="footer-bottom-text">
        © 2025 RB Personal Trainer. All rights reserved.
      </p>
    </div>
</footer>

<!-- JS EXTERNO GERAL DO SITE -->
<script src="../script.js"></script>

</body>
</html>
