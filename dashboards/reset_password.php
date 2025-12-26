<?php
// /dashboards/reset_password.php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
require_role(['user', 'client', 'pro', 'admin']);

$pdo    = getPDO();
$userId = (int)($_SESSION['user_id'] ?? 0);

$errors  = [];
$success = '';

/**
 * CSRF
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = (string)$_SESSION['csrf_token'];

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// PROCESSA FORMULÁRIO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = (string)($_POST['csrf_token'] ?? '');
    if ($posted_csrf === '' || !hash_equals($csrf_token, $posted_csrf)) {
        $errors[] = 'Security validation failed. Please refresh the page and try again.';
    } else {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword     = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        // Validações básicas
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $errors[] = 'Please fill in all password fields.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        }

        if (empty($errors)) {
            // Busca o hash atual do usuário
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $errors[] = 'User not found. Please contact support.';
            } elseif (!password_verify($currentPassword, (string)$user['password_hash'])) {
                $errors[] = 'Your current password is incorrect.';
            } else {
                // Gera novo hash e atualiza
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                $update->execute([$newHash, $userId]);

                // Destrói sessão e força login novamente
                session_unset();
                session_destroy();

                // redireciona para login com flag (LOGIN no ROOT)
                header('Location: ../login.php?password_updated=1');
                exit();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Change Password | RB Personal Trainer</title>
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

  <link rel="stylesheet" href="/assets/css/global.css">
  <link rel="stylesheet" href="/assets/css/login.css">
  <link rel="stylesheet" href="/assets/css/header.css">
  <link rel="stylesheet" href="/assets/css/footer.css">
</head>
<body>

<header id="rb-static-header" class="rbf1-header">
  <div class="rbf1-topbar">
    <a href="dashboard_client.php" class="rbf1-logo">
      <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
    </a>

    <nav class="rbf1-nav" id="rbf1-nav">
      <ul>
        <li><a href="dashboard_client.php">Dashboard</a></li>
        <li><a href="client_profile.php">Profile</a></li>
        <li><a href="client_goals.php">Goals</a></li>
        <li><a href="client_photos.php">Photos</a></li>
        <li><a href="client_nutrition.php">Nutrition</a></li>

        <!-- Logout só no mobile -->
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

<main class="login-main">
  <section class="login-section">
    <div class="login-container">
      <div class="login-logo">
        <img src="/assets/images/logo.svg" alt="RB Personal Trainer Logo">
      </div>

      <p class="login-subtitle">
        Change your password to keep your account secure.
      </p>

      <?php if (!empty($errors)): ?>
        <div class="login-errors">
          <ul>
            <?php foreach ($errors as $err): ?>
              <li><?php echo e((string)$err); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form
        method="post"
        action="reset_password.php"
        class="login-form"
        novalidate
      >
        <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">

        <div class="form-group">
          <label for="current_password">Current password</label>
          <input
            type="password"
            id="current_password"
            name="current_password"
            placeholder="Enter your current password"
            required
          >
        </div>

        <div class="form-group">
          <label for="new_password">New password</label>
          <input
            type="password"
            id="new_password"
            name="new_password"
            placeholder="Choose a new password"
            required
          >
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm new password</label>
          <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            placeholder="Repeat your new password"
            required
          >
        </div>

        <button type="submit" class="login-button">
          Update password
        </button>
      </form>
    </div>
  </section>
</main>

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
        <li><a href="dashboard_client.php">Dashboard</a></li>
        <li><a href="client_profile.php">Profile</a></li>
        <li><a href="client_goals.php">Goals</a></li>
        <li><a href="client_workouts.php">Workouts</a></li>
        <li><a href="client_nutrition.php">Nutrition</a></li>
        <li><a href="messages.php">Messages</a></li>
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

<script src="/script.js"></script>

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

</body>
</html>
